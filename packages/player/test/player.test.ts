import { afterEach, describe, expect, it, vi } from 'vitest';
import { createPlayer } from '../src/index.js';
import type { Player, PlayerEventType } from '../src/index.js';
import { definitionDocument, fakeFetch, key, manualClock, mediaDocument, memoryStorage, settle } from './support.js';

const players: Player[] = [];
function mount(options: Parameters<typeof createPlayer>[1] = {}): { player: Player; container: HTMLElement; shadow: ShadowRoot } {
  const container = document.createElement('div');
  document.body.append(container);
  const player = createPlayer(container, { pauseWhenHidden: false, ...options });
  players.push(player);
  return { player, container, shadow: player.element.shadowRoot as ShadowRoot };
}
function record(player: Player): { type: string; detail: unknown }[] {
  const events: { type: string; detail: unknown }[] = [];
  for (const type of ['ready', 'move', 'state-change', 'progress', 'complete', 'error'] as PlayerEventType[]) {
    player.subscribe(type, (detail) => events.push({ type, detail }));
  }
  return events;
}
const control = (shadow: ShadowRoot, name: string): HTMLButtonElement =>
  shadow.querySelector(`[data-control="${name}"]`) as HTMLButtonElement;
const tile = (shadow: ShadowRoot, id: string): HTMLButtonElement => shadow.querySelector(`[data-tile="${id}"]`) as HTMLButtonElement;

afterEach(() => {
  for (const player of players.splice(0)) player.destroy();
  document.body.replaceChildren();
});

describe('createPlayer', () => {
  it('mounts an isolated shadow root with an empty state', () => {
    const { player, container, shadow } = mount();
    expect(container.querySelector('.image-puzzle-player')).toBe(player.element);
    expect(player.view).toBe('empty');
    expect(shadow.querySelector('.message')?.textContent).toBe('No puzzle loaded.');
    expect(shadow.querySelector('style')).not.toBeNull();
    expect(document.head.querySelector('style')).toBeNull();
  });

  it('refuses a second player on the same element until the first is destroyed', () => {
    const container = document.createElement('div');
    const first = createPlayer(container);
    expect(() => createPlayer(container)).toThrow(/already hosts/);
    first.destroy();
    const second = createPlayer(container);
    expect(container.children).toHaveLength(1);
    second.destroy();
    expect(container.children).toHaveLength(0);
  });

  it('loads a definition object and renders capability-driven controls', async () => {
    const { player, shadow } = mount();
    const events = record(player);
    await player.load(definitionDocument('sliding'));
    expect(player.view).toBe('ready');
    expect(events.map((event) => event.type)).toEqual(['state-change', 'progress', 'ready']);
    expect(shadow.querySelectorAll('button.tile')).toHaveLength(8);
    expect(control(shadow, 'hint').hidden).toBe(true); // sliding has no hint capability
    expect(control(shadow, 'undo').hidden).toBe(false);
    expect(control(shadow, 'undo').disabled).toBe(true);
    expect(control(shadow, 'zoom-in').hidden).toBe(true);

    await player.load(definitionDocument('rotation'));
    expect(control(shadow, 'hint').hidden).toBe(false);
  });

  it('plays with the keyboard: arrows move a roving focus, Enter acts', async () => {
    const { player, shadow } = mount();
    const events = record(player);
    await player.load(definitionDocument('sliding'));
    expect(tile(shadow, 't0').tabIndex).toBe(0);
    key(tile(shadow, 't0'), 'ArrowRight');
    expect(tile(shadow, 't6').tabIndex).toBe(0);
    expect(tile(shadow, 't0').tabIndex).toBe(-1);
    key(tile(shadow, 't6'), 'Enter');
    expect(player.snapshot().moves).toBe(1);
    expect(events.filter((event) => event.type === 'move')).toHaveLength(1);
    expect(shadow.querySelector('[aria-live]')?.textContent).toBe('Moved tile 7.');
    expect(control(shadow, 'undo').disabled).toBe(false);
    // The tile now sits in the centre cell.
    expect(tile(shadow, 't6').style.left).toBe('33.3333%');
    expect(tile(shadow, 't6').style.top).toBe('33.3333%');
    control(shadow, 'undo').click();
    expect(tile(shadow, 't6').style.top).toBe('0%');
  });

  it('swaps two tiles selected with Enter and cancels with Escape', async () => {
    const { player, shadow } = mount();
    await player.load(definitionDocument('swap'));
    const ids = player.exportState().board as { cells: string[] };
    const [a, b] = ids.cells as [string, string];
    key(tile(shadow, a), 'Enter');
    expect(tile(shadow, a).getAttribute('aria-pressed')).toBe('true');
    key(tile(shadow, a), 'Escape');
    expect(tile(shadow, a).getAttribute('aria-pressed')).toBe('false');
    key(tile(shadow, a), 'Enter');
    key(tile(shadow, a), 'ArrowRight');
    key(tile(shadow, b), 'Enter');
    expect((player.exportState().board as { cells: string[] }).cells.slice(0, 2)).toEqual([b, a]);
  });

  it('rotates clockwise with Enter and counter-clockwise with Shift+Enter', async () => {
    const { player, shadow } = mount();
    await player.load(definitionDocument('rotation'));
    const before = (player.exportState().board as { orientations: number[] }).orientations[0] ?? 0;
    key(tile(shadow, 't0'), 'Enter');
    expect((player.exportState().board as { orientations: number[] }).orientations[0]).toBe((before + 90) % 360);
    key(tile(shadow, 't0'), 'Enter', { shiftKey: true });
    expect((player.exportState().board as { orientations: number[] }).orientations[0]).toBe(before);
  });

  it('moves a strip: Enter picks it up, arrows choose the slot, Enter drops it', async () => {
    const { player, shadow } = mount();
    await player.load(definitionDocument('strips'));
    const order = (player.exportState().board as { order: string[] }).order;
    const first = order[0] as string;
    key(tile(shadow, first), 'Enter');
    key(tile(shadow, first), 'ArrowDown');
    key(tile(shadow, first), 'ArrowDown');
    key(tile(shadow, first), 'Enter');
    expect((player.exportState().board as { order: string[] }).order.indexOf(first)).toBe(2);
  });

  it('moves a jigsaw piece with the arrow keys and drops it with Enter', async () => {
    const { player, shadow } = mount();
    await player.load(definitionDocument('jigsaw'));
    const piece = shadow.querySelector('[data-piece="r0c0"]') as HTMLButtonElement;
    const before = (player.exportState().board as { readonly pieces: readonly { x: number; y: number }[] }).pieces[0];
    key(piece, 'ArrowLeft', { shiftKey: true });
    expect(player.snapshot().moves).toBe(0); // not dispatched yet
    key(piece, 'Escape');
    key(piece, 'ArrowLeft');
    key(piece, 'Enter');
    const after = (player.exportState().board as { readonly pieces: readonly { x: number; y: number }[] }).pieces[0];
    expect(player.snapshot().moves).toBe(1);
    expect(after?.x).toBeLessThan(before?.x ?? 0);
    expect(control(shadow, 'zoom-in').hidden).toBe(false);
  });

  it('hint highlights the suggested tile and counts the hint', async () => {
    const { player, shadow } = mount();
    await player.load(definitionDocument('rotation'));
    const action = player.hint();
    expect(action).not.toBeNull();
    expect(shadow.querySelector('.is-hint')).not.toBeNull();
    expect(player.snapshot().hintsUsed).toBe(1);
    expect(shadow.querySelector('[data-status="hints"]')?.textContent).toBe('Hints: 1');
  });

  it('completes and announces completion', async () => {
    const clock = manualClock();
    const { player, shadow } = mount({ clock: clock.now });
    const events = record(player);
    await player.load(definitionDocument('strips'));
    for (let i = 0; i < 50 && player.engine.status !== 'complete'; i++) {
      clock.advance(1000);
      const action = player.engine.hint();
      if (action !== null) player.dispatchAction(action);
    }
    expect(events.at(-1)?.type).toBe('complete');
    expect(shadow.querySelector('[aria-live]')?.textContent).toMatch(/^Puzzle complete in \d+ moves and 0:0\d\.$/);
    expect(control(shadow, 'start').disabled).toBe(true);
  });

  it('fetches src relative to the document and resolves assets against the definition URL', async () => {
    const fetch = fakeFetch({ '/puzzles/a.json': definitionDocument('jigsaw') });
    const { player, shadow } = mount({ fetch, src: '/puzzles/a.json' });
    await settle();
    expect(player.view).toBe('ready');
    expect(fetch.calls[0]?.url).toBe(new URL('/puzzles/a.json', document.baseURI).href);
    const image = shadow.querySelector('.piece img') as HTMLImageElement;
    expect(image.src).toBe(new URL('/puzzles/pieces/', document.baseURI).href + 'piece_0_0.png');
  });

  it('aborts a stale load when another puzzle is selected', async () => {
    const fetch = fakeFetch({ 'b.json': definitionDocument('swap') }, ['slow.json']);
    const { player } = mount({ fetch });
    const events = record(player);
    const first = player.load('slow.json');
    const second = player.load('b.json');
    await Promise.all([first, second]);
    expect(fetch.calls[0]?.signal?.aborted).toBe(true);
    expect(player.engine.definition?.kind).toBe('swap');
    expect(events.filter((event) => event.type === 'ready')).toHaveLength(1);
    expect(events.filter((event) => event.type === 'error')).toHaveLength(0);
  });

  it.each([
    ['HTTP errors', fakeFetch({}), /HTTP 404/],
    ['invalid JSON', fakeFetch({ 'x.json': '{nope' }), /not valid JSON/],
  ])('reports %s as a load error', async (_name, fetch, pattern) => {
    const { player, shadow } = mount({ fetch });
    const events = record(player);
    await expect(player.load('x.json')).rejects.toThrow(pattern);
    expect(player.view).toBe('error');
    expect(events).toEqual([{ type: 'error', detail: { code: 'load', message: expect.stringMatching(pattern) as unknown } }]);
    expect(shadow.querySelector('.message')?.getAttribute('data-kind')).toBe('error');
  });

  it('rejects an invalid definition once and keeps the current game', async () => {
    const { player } = mount();
    await player.load(definitionDocument('swap'));
    const id = player.engine.definition?.id;
    const events = record(player);
    await expect(player.load({ ...definitionDocument('swap'), id: 'gd1-' + '0'.repeat(64) })).rejects.toThrow();
    expect(events.map((event) => event.type)).toEqual(['error']);
    expect(player.engine.definition?.id).toBe(id);
  });

  it('rejects incompatible imported state without touching the game', async () => {
    const { player } = mount();
    await player.load(definitionDocument('swap'));
    player.dispatchAction(player.engine.legalActions()[0]);
    const before = player.exportState();
    expect(() => {
      player.importState({ ...before, definitionId: 'gd1-' + 'f'.repeat(64) });
    }).toThrow();
    expect(player.exportState()).toEqual(before);
  });

  it('persists progress and restores it in a new player', async () => {
    const storage = memoryStorage();
    const definition = definitionDocument('swap');
    const first = mount({ persist: { storage, debounceMs: 0 } });
    await first.player.load(definition);
    first.player.dispatchAction(first.player.engine.legalActions()[0]);
    first.player.suspend();
    expect(storage.data.size).toBe(1);
    first.player.destroy();

    const second = mount({ persist: { storage } });
    await second.player.load(definition);
    expect(second.player.snapshot().moves).toBe(1);
  });

  it('keeps the game when the stored entry is corrupt', async () => {
    const storage = memoryStorage();
    const definition = definitionDocument('swap');
    const { player } = mount({ persist: { storage } });
    const events = record(player);
    storage.setItem(`image-puzzle:${String(definition['id'])}`, '{broken');
    await player.load(definition);
    expect(player.view).toBe('ready');
    expect(events.find((event) => event.type === 'error')?.detail).toEqual({
      code: 'storage',
      message: 'The saved game is not valid JSON.',
    });
    expect(storage.getItem(`image-puzzle:${String(definition['id'])}`)).toBe('{broken');
  });

  it('pauses while the document is hidden and resumes afterwards', async () => {
    const clock = manualClock();
    const container = document.createElement('div');
    const player = createPlayer(container, { clock: clock.now });
    players.push(player);
    await player.load(definitionDocument('swap'));
    player.start();
    const hidden = vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('hidden');
    document.dispatchEvent(new Event('visibilitychange'));
    expect(player.engine.status).toBe('paused');
    clock.advance(60_000);
    hidden.mockReturnValue('visible');
    document.dispatchEvent(new Event('visibilitychange'));
    expect(player.engine.status).toBe('running');
    expect(player.snapshot().elapsedMs).toBe(0);
    hidden.mockRestore();
  });

  it('switches language live and translates every visible control', async () => {
    const { player, shadow } = mount({ locale: 'en' });
    await player.load(definitionDocument('rotation'));
    player.setLocale('it');
    expect(control(shadow, 'reset').textContent).toBe('Ricomincia');
    expect(tile(shadow, 't0').getAttribute('aria-label')).toMatch(/^Tessera 1, riga 1, colonna 1, ruotata di \d+ gradi$/);
    expect(shadow.querySelector('.root')?.getAttribute('lang')).toBe('it');
    player.setLocale('fr-CA', { reset: 'Recommencer' });
    expect(control(shadow, 'reset').textContent).toBe('Recommencer');
    expect(control(shadow, 'undo').textContent).toBe('Undo');
  });

  it('hides the toolbar when controls are disabled', () => {
    const { shadow } = mount({ controls: false });
    expect((shadow.querySelector('.toolbar') as HTMLElement).hidden).toBe(true);
  });

  it('destroy releases engine, listeners, timers and DOM', async () => {
    const { player, container } = mount();
    const listener = vi.fn();
    player.subscribe('state-change', listener);
    await player.load(definitionDocument('swap'));
    player.start();
    listener.mockClear();
    player.destroy();
    player.destroy(); // idempotent
    expect(container.children).toHaveLength(0);
    expect(player.engine.status).toBe('destroyed');
    await expect(player.load(definitionDocument('swap'))).rejects.toThrow(/destroyed/);
    expect(listener).not.toHaveBeenCalled();
  });

  it('isolates multiple instances on one page', async () => {
    const a = mount();
    const b = mount();
    await a.player.load(definitionDocument('swap'));
    await b.player.load(definitionDocument('swap'));
    a.player.dispatchAction(a.player.engine.legalActions()[0]);
    expect(a.player.snapshot().moves).toBe(1);
    expect(b.player.snapshot().moves).toBe(0);
    expect(a.shadow.querySelector('[data-status="moves"]')?.textContent).toBe('Moves: 1');
    expect(b.shadow.querySelector('[data-status="moves"]')?.textContent).toBe('Moves: 0');
  });

  it('builds labels with text nodes only', async () => {
    const { player, shadow } = mount({ messages: { start: '<img src=x onerror=alert(1)>' } });
    await player.load(definitionDocument('swap'));
    expect(control(shadow, 'start').textContent).toBe('<img src=x onerror=alert(1)>');
    expect(shadow.querySelector('img')).toBeNull();
  });
});

describe('alternative piece images', () => {
  it('gives responsive pieces a srcset of every set plus the full-resolution image', async () => {
    const { player, shadow } = mount({ baseUrl: 'https://cdn.example/puzzle/' });
    await player.load(mediaDocument('resolutions'));
    const images = [...shadow.querySelectorAll<HTMLImageElement>('.piece img')];
    expect(images).toHaveLength(player.engine.definition?.tiles.length ?? -1);
    const first = images[0];
    const tile = player.engine.definition?.tiles[0];
    if (first === undefined || tile === undefined) throw new Error('no piece');
    const file = String(tile.attributes['image']).split('/').pop() ?? '';
    const rendered = tile.attributes['rendered'] as { width: number };
    expect(first.getAttribute('srcset')).toBe(
      [
        `https://cdn.example/puzzle/pieces-thumbnail/${file} ${String(Math.max(1, Math.round((rendered.width * 30) / 120)))}w`,
        `https://cdn.example/puzzle/pieces-mobile/${file} ${String(Math.max(1, Math.round((rendered.width * 60) / 120)))}w`,
        `https://cdn.example/puzzle/${String(tile.attributes['image'])} ${String(rendered.width)}w`,
      ].join(', '),
    );
    // Without ResizeObserver (jsdom) the size is estimated from the viewport.
    expect(first.getAttribute('sizes')).toMatch(/^\d+\.\d{3}vw$/);
    expect(first.getAttribute('src')).toBe(`https://cdn.example/puzzle/${String(tile.attributes['image'])}`);
  });

  it('draws atlas pieces into one canvas per frame and loads the atlas once', async () => {
    const { player, shadow } = mount();
    await player.load(mediaDocument('atlas'));
    const definition = player.engine.definition;
    const canvases = [...shadow.querySelectorAll<HTMLCanvasElement>('.piece canvas.piece-image')];
    expect(shadow.querySelectorAll('.piece img')).toHaveLength(0);
    expect(canvases).toHaveLength(definition?.tiles.length ?? -1);
    const frames = definition?.media.atlas?.frames ?? {};
    canvases.forEach((canvas, i) => {
      const id = definition?.tiles[i]?.id ?? '';
      expect([canvas.width, canvas.height]).toEqual([frames[id]?.width, frames[id]?.height]);
    });
  });
});
