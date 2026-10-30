import { readdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { Player, PlayerBindingOptions, PlayerHandlers } from '../src/index.js';
import { definitionDocument, fakeFetch, manualClock, settle } from './support.js';

/*
 * One behavioural contract for every way of mounting a player from a
 * framework: the shared binding, the React component and the Vue component
 * run these same scenarios, so an adapter cannot drift from the others.
 */

export type ContractProps = PlayerBindingOptions;

export interface Mounted {
  /** The live player (throws when unmounted). */
  player(): Player;
  /** Re-renders with new props and handlers, then lets effects settle. */
  update(props: ContractProps, handlers?: PlayerHandlers): Promise<void>;
  unmount(): Promise<void>;
}

export type Mount = (container: HTMLElement, props: ContractProps, handlers: PlayerHandlers) => Promise<Mounted>;

const MODES = readdirSync(join(dirname(fileURLToPath(import.meta.url)), '../../../conformance'))
  .filter((file) => file.startsWith('mode-'))
  .map((file) => file.slice('mode-'.length, -'.json'.length));

function hostOf(container: HTMLElement): HTMLElement | null {
  return container.querySelector<HTMLElement>('.image-puzzle-player');
}

function definitionId(name: string): string {
  return String(definitionDocument(name)['id']);
}

async function ready(mounted: Mounted): Promise<Player> {
  await vi.waitFor(() => {
    expect(mounted.player().view).toBe('ready');
  });
  return mounted.player();
}

export function describeAdapterContract(name: string, mount: Mount): void {
  describe(`${name}: adapter contract`, () => {
    const live: Mounted[] = [];
    async function open(props: ContractProps, handlers: PlayerHandlers = {}, container = document.createElement('div')): Promise<Mounted> {
      document.body.append(container);
      const mounted = await mount(container, props, handlers);
      live.push(mounted);
      return mounted;
    }

    afterEach(async () => {
      for (const mounted of live.splice(0)) await mounted.unmount();
      document.body.replaceChildren();
    });

    it('mounts one player and reports ready once for the loaded definition', async () => {
      const onReady = vi.fn();
      const container = document.createElement('div');
      const mounted = await open({ definition: definitionDocument('swap') }, { ready: onReady }, container);
      await ready(mounted);
      expect(container.querySelectorAll('.image-puzzle-player')).toHaveLength(1);
      expect(onReady).toHaveBeenCalledTimes(1);
      expect(mounted.player().engine.definition?.id).toBe(definitionId('swap'));
    });

    it.each(MODES)('plays the %s conformance definition with the shared rules', async (mode) => {
      const onMove = vi.fn();
      const onStateChange = vi.fn();
      const mounted = await open({ definition: definitionDocument(mode) }, { move: onMove, 'state-change': onStateChange });
      const player = await ready(mounted);
      const action = player.engine.legalActions()[0];
      expect(action).toBeDefined();
      expect(player.dispatchAction(action).ok).toBe(true);
      expect(onMove).toHaveBeenCalledTimes(1);
      expect(onStateChange).toHaveBeenCalled();
      expect(player.snapshot().moves).toBe(1);
    });

    it('reports completion once', async () => {
      const clock = manualClock();
      const onComplete = vi.fn();
      const mounted = await open({ definition: definitionDocument('strips'), clock: clock.now }, { complete: onComplete });
      const player = await ready(mounted);
      for (let i = 0; i < 50 && player.engine.status !== 'complete'; i++) {
        clock.advance(1000);
        const hint = player.engine.hint();
        if (hint !== null) player.dispatchAction(hint);
      }
      expect(onComplete).toHaveBeenCalledTimes(1);
      expect(onComplete.mock.calls[0]?.[0]).toMatchObject({ moves: player.snapshot().moves });
    });

    it('restores a saved state passed as initialState', async () => {
      const definition = definitionDocument('swap');
      const first = await open({ definition });
      const player = await ready(first);
      player.dispatchAction(player.engine.legalActions()[0]);
      player.dispatchAction(player.engine.legalActions()[0]);
      const saved = player.exportState();
      await first.unmount();

      const second = await open({ definition, initialState: saved });
      const restored = await ready(second);
      expect(restored.snapshot().moves).toBe(2);
      expect(restored.exportState().board).toEqual(saved.board);
    });

    it('starts later puzzles fresh instead of applying the first initialState to them', async () => {
      const definition = definitionDocument('swap');
      const first = await open({ definition });
      const player = await ready(first);
      player.dispatchAction(player.engine.legalActions()[0]);
      const saved = player.exportState();
      await first.unmount();

      const onError = vi.fn();
      const second = await open({ definition, initialState: saved }, { error: onError });
      expect((await ready(second)).snapshot().moves).toBe(1);
      const rotation = definitionDocument('rotation');
      await second.update({ definition: rotation, initialState: saved }, { error: onError });
      await vi.waitFor(() => {
        expect(second.player().engine.definition?.id).toBe(definitionId('rotation'));
      });
      expect(second.player().snapshot().moves).toBe(0);
      expect(onError).not.toHaveBeenCalled();
    });

    it('switches puzzles on a new definition, but not on a new initialState alone', async () => {
      const onReady = vi.fn();
      const mounted = await open({ definition: definitionDocument('swap') }, { ready: onReady });
      const player = await ready(mounted);
      player.dispatchAction(player.engine.legalActions()[0]);

      await mounted.update({ definition: definitionDocument('swap'), initialState: null }, { ready: onReady });
      // A structurally equal but new object is a new source: compare by identity.
      await vi.waitFor(() => {
        expect(onReady).toHaveBeenCalledTimes(2);
      });
      expect(mounted.player().snapshot().moves).toBe(0);

      const definition = definitionDocument('rotation');
      await mounted.update({ definition }, { ready: onReady });
      await vi.waitFor(() => {
        expect(mounted.player().engine.definition?.id).toBe(definitionId('rotation'));
      });
      mounted.player().dispatchAction(mounted.player().engine.legalActions()[0]);
      await mounted.update({ definition, initialState: { stale: true } }, { ready: onReady });
      await settle();
      expect(mounted.player().snapshot().moves).toBe(1);
      expect(onReady).toHaveBeenCalledTimes(3);
    });

    it('cancels a stale src load when the source changes', async () => {
      const fetchImpl = fakeFetch({ 'swap.json': definitionDocument('swap') }, ['slow.json']);
      const onError = vi.fn();
      const mounted = await open({ src: '/puzzles/slow.json', fetch: fetchImpl }, { error: onError });
      expect(mounted.player().view).toBe('loading');
      await mounted.update({ src: '/puzzles/swap.json', fetch: fetchImpl }, { error: onError });
      await ready(mounted);
      expect(fetchImpl.calls[0]?.signal?.aborted).toBe(true);
      expect(mounted.player().engine.definition?.id).toBe(definitionId('swap'));
      expect(onError).not.toHaveBeenCalled();
    });

    it('reports a failed load through the error handler', async () => {
      const onError = vi.fn();
      const mounted = await open({ src: '/puzzles/missing.json', fetch: fakeFetch({}) }, { error: onError });
      await vi.waitFor(() => {
        expect(onError).toHaveBeenCalledTimes(1);
      });
      expect(mounted.player().view).toBe('error');
    });

    it('calls the newest handlers without reloading', async () => {
      const first = vi.fn();
      const second = vi.fn();
      const definition = definitionDocument('swap');
      const mounted = await open({ definition }, { move: first });
      const player = await ready(mounted);
      await mounted.update({ definition }, { move: second });
      player.dispatchAction(player.engine.legalActions()[0]);
      expect(first).not.toHaveBeenCalled();
      expect(second).toHaveBeenCalledTimes(1);
      expect(mounted.player()).toBe(player);
    });

    it('relabels on a locale change and keeps the game', async () => {
      const definition = definitionDocument('swap');
      const mounted = await open({ definition, locale: 'en' });
      const player = await ready(mounted);
      player.dispatchAction(player.engine.legalActions()[0]);
      const label = (): string | null | undefined => hostOf(document.body)?.shadowRoot?.querySelector('[data-control="undo"]')?.textContent;
      expect(label()).toBe('Undo');
      await mounted.update({ definition, locale: 'it' });
      expect(label()).toBe('Annulla');
      expect(mounted.player()).toBe(player);
      expect(player.snapshot().moves).toBe(1);
    });

    it('applies theme and themeClass to the player host', async () => {
      document.documentElement.classList.add('dark');
      try {
        const definition = definitionDocument('swap');
        const container = document.createElement('div');
        const mounted = await open({ definition, theme: 'light' }, {}, container);
        expect(hostOf(container)?.getAttribute('theme')).toBe('light');
        await mounted.update({ definition, themeClass: 'dark' });
        expect(hostOf(container)?.hasAttribute('theme')).toBe(false);
        expect(hostOf(container)?.getAttribute('data-theme')).toBe('dark');
        document.documentElement.classList.remove('dark');
        await vi.waitFor(() => {
          expect(hostOf(container)?.getAttribute('data-theme')).toBe('light');
        });
      } finally {
        document.documentElement.classList.remove('dark');
      }
    });

    it('keeps multiple instances independent', async () => {
      const a = await open({ definition: definitionDocument('swap') });
      const b = await open({ definition: definitionDocument('swap') });
      const playerA = await ready(a);
      const playerB = await ready(b);
      playerA.dispatchAction(playerA.engine.legalActions()[0]);
      expect(playerA.snapshot().moves).toBe(1);
      expect(playerB.snapshot().moves).toBe(0);
    });

    it('disposes the player, its DOM and its listeners on unmount', async () => {
      const onStateChange = vi.fn();
      const container = document.createElement('div');
      const mounted = await open({ definition: definitionDocument('swap') }, { 'state-change': onStateChange }, container);
      const player = await ready(mounted);
      onStateChange.mockClear();
      await mounted.unmount();
      live.splice(live.indexOf(mounted), 1);
      expect(hostOf(container)).toBeNull();
      expect(player.engine.status).toBe('destroyed');
      expect(onStateChange).not.toHaveBeenCalled();
    });
  });
}
