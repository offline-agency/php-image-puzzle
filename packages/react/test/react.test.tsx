import { StrictMode, act, createRef } from 'react';
import { createRoot } from 'react-dom/client';
import type { Root } from 'react-dom/client';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineImagePuzzlePlayer } from '@offline-agency/image-puzzle-player';
import type { ImagePuzzlePlayerElement, PlayerEventMap, PlayerHandlers } from '@offline-agency/image-puzzle-player';
import type { DetailedHTMLProps, HTMLAttributes } from 'react';
import { describeAdapterContract } from '../../player/test/adapter-contract.js';
import type { ContractProps, Mounted } from '../../player/test/adapter-contract.js';
import { definitionDocument, settle } from '../../player/test/support.js';
import { ImagePuzzlePlayer } from '../src/index.js';
import type { ImagePuzzlePlayerCallbacks, ImagePuzzlePlayerHandle } from '../src/index.js';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

// The JSX declaration documented in packages/react/README.md for the Web Component route.
declare module 'react' {
  // eslint-disable-next-line @typescript-eslint/no-namespace
  namespace JSX {
    interface IntrinsicElements {
      'image-puzzle-player': DetailedHTMLProps<HTMLAttributes<ImagePuzzlePlayerElement>, ImagePuzzlePlayerElement> & {
        src?: string;
        locale?: string;
        definition?: object | null;
        state?: object | null;
        'onpuzzle-ready'?: (event: CustomEvent<PlayerEventMap['ready']>) => void;
        'onpuzzle-move'?: (event: CustomEvent<PlayerEventMap['move']>) => void;
        'onpuzzle-complete'?: (event: CustomEvent<PlayerEventMap['complete']>) => void;
        'onpuzzle-error'?: (event: CustomEvent<PlayerEventMap['error']>) => void;
      };
    }
  }
}

function callbacks(handlers: PlayerHandlers): ImagePuzzlePlayerCallbacks {
  return {
    onReady: handlers.ready,
    onMove: handlers.move,
    onStateChange: handlers['state-change'],
    onProgress: handlers.progress,
    onComplete: handlers.complete,
    onError: handlers.error,
  };
}

async function mountReact(
  container: HTMLElement,
  props: ContractProps,
  handlers: PlayerHandlers,
  strict = false,
): Promise<Mounted & { root: Root }> {
  const root = createRoot(container);
  const handle = createRef<ImagePuzzlePlayerHandle>();
  let currentHandlers = handlers;
  const render = async (next: ContractProps): Promise<void> => {
    const element = <ImagePuzzlePlayer ref={handle} {...next} {...callbacks(currentHandlers)} />;
    await act(async () => {
      root.render(strict ? <StrictMode>{element}</StrictMode> : element);
      await Promise.resolve();
    });
    await settle();
  };
  await render(props);
  let unmounted = false;
  return {
    root,
    player() {
      const player = handle.current?.player ?? null;
      if (unmounted || player === null) throw new Error('unmounted');
      return player;
    },
    async update(next, nextHandlers) {
      if (nextHandlers !== undefined) currentHandlers = nextHandlers;
      await render(next);
    },
    async unmount() {
      if (unmounted) return;
      unmounted = true;
      act(() => {
        root.unmount();
      });
      await settle();
    },
  };
}

describeAdapterContract('React <ImagePuzzlePlayer>', (container, props, handlers) => mountReact(container, props, handlers));
describeAdapterContract('React <ImagePuzzlePlayer> in StrictMode', (container, props, handlers) =>
  mountReact(container, props, handlers, true),
);

describe('React adapter specifics', () => {
  afterEach(() => {
    document.body.replaceChildren();
  });

  it('survives the Strict Mode setup/cleanup/setup cycle without duplicates or leaks', async () => {
    const container = document.createElement('div');
    document.body.append(container);
    const onReady = vi.fn();
    const onMove = vi.fn();
    const mounted = await mountReact(container, { definition: definitionDocument('swap') }, { ready: onReady, move: onMove }, true);
    await vi.waitFor(() => {
      expect(onReady).toHaveBeenCalledTimes(1);
    });
    expect(container.querySelectorAll('.image-puzzle-player')).toHaveLength(1);
    const player = mounted.player();
    player.dispatchAction(player.engine.legalActions()[0]);
    expect(onMove).toHaveBeenCalledTimes(1);
    await mounted.unmount();
    expect(container.querySelector('.image-puzzle-player')).toBeNull();
    expect(player.engine.status).toBe('destroyed');
  });

  it('passes id, className and style to the container element', async () => {
    const container = document.createElement('div');
    document.body.append(container);
    const root = createRoot(container);
    await act(async () => {
      root.render(<ImagePuzzlePlayer id="puzzle" className="board" style={{ maxWidth: '40rem' }} />);
      await Promise.resolve();
    });
    const element = container.firstElementChild as HTMLElement;
    expect(element.id).toBe('puzzle');
    expect(element.className).toBe('board');
    expect(element.style.maxWidth).toBe('40rem');
    // Without a source the player shows its empty state.
    expect(element.querySelector('.image-puzzle-player')?.getAttribute('data-state')).toBe('empty');
    act(() => {
      root.unmount();
    });
  });

  it('remounts with new creation options when the key changes', async () => {
    const container = document.createElement('div');
    document.body.append(container);
    const root = createRoot(container);
    const handle = createRef<ImagePuzzlePlayerHandle>();
    const definition = definitionDocument('swap');
    await act(async () => {
      root.render(<ImagePuzzlePlayer key="a" ref={handle} definition={definition} controls />);
      await Promise.resolve();
    });
    const first = handle.current?.player;
    const toolbar = (): boolean | 'until-found' | undefined =>
      container.querySelector('.image-puzzle-player')?.shadowRoot?.querySelector<HTMLElement>('.toolbar')?.hidden;
    expect(toolbar()).toBe(false);
    await act(async () => {
      root.render(<ImagePuzzlePlayer key="a" ref={handle} definition={definition} controls={false} />);
      await Promise.resolve();
    });
    expect(handle.current?.player).toBe(first);
    expect(toolbar()).toBe(false);
    await act(async () => {
      root.render(<ImagePuzzlePlayer key="b" ref={handle} definition={definition} controls={false} />);
      await Promise.resolve();
    });
    expect(handle.current?.player).not.toBe(first);
    expect(first?.engine.status).toBe('destroyed');
    expect(toolbar()).toBe(true);
    act(() => {
      root.unmount();
    });
  });

  it('drives the Web Component directly: React 19 sets objects as properties and listens to puzzle-* events', async () => {
    defineImagePuzzlePlayer();
    const container = document.createElement('div');
    document.body.append(container);
    const root = createRoot(container);
    const definition = definitionDocument('swap');
    const onReady = vi.fn();
    const onMove = vi.fn();
    await act(async () => {
      root.render(<image-puzzle-player definition={definition} locale="it" onpuzzle-ready={onReady} onpuzzle-move={onMove} />);
      await Promise.resolve();
    });
    const element = container.querySelector('image-puzzle-player') as ImagePuzzlePlayerElement;
    await vi.waitFor(() => {
      expect(onReady).toHaveBeenCalledTimes(1);
    });
    // An object reaches the property, not a serialised attribute.
    expect(element.definition).toBe(definition);
    expect(element.hasAttribute('definition')).toBe(false);
    expect(element.getAttribute('locale')).toBe('it');
    const ready = onReady.mock.calls[0]?.[0] as CustomEvent<PlayerEventMap['ready']>;
    expect(ready.detail.definitionId).toBe(String(definition['id']));
    element.dispatchAction(element.engine.legalActions()[0]);
    expect(onMove).toHaveBeenCalledTimes(1);
    act(() => {
      root.unmount();
    });
  });

  it('exposes load, reset and state operations through the ref', async () => {
    const container = document.createElement('div');
    document.body.append(container);
    const root = createRoot(container);
    const handle = createRef<ImagePuzzlePlayerHandle>();
    await act(async () => {
      root.render(<ImagePuzzlePlayer ref={handle} />);
      await Promise.resolve();
    });
    const api = handle.current;
    if (api === null) throw new Error('no handle');
    await act(async () => {
      await api.load(definitionDocument('sliding'));
    });
    const player = api.player;
    if (player === null) throw new Error('no player');
    player.dispatchAction(player.engine.legalActions()[0]);
    const saved = api.exportState();
    expect(saved.moves).toBe(1);
    api.reset();
    expect(player.snapshot().moves).toBe(0);
    api.importState(saved);
    expect(player.snapshot().moves).toBe(1);
    act(() => {
      root.unmount();
    });
    expect(handle.current).toBeNull();
    // A handle kept past unmount refers to the destroyed player.
    expect(() => {
      api.reset();
    }).toThrow(/destroyed/);
  });
});
