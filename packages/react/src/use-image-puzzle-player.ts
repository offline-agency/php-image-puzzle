import { bindPlayer } from '@offline-agency/image-puzzle-player';
import type { Player, PlayerBinding, PlayerBindingOptions, PlayerEventMap, PlayerHandlers } from '@offline-agency/image-puzzle-player';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import type { RefObject } from 'react';

/** Event callbacks; the newest ones are always called, without resubscribing. */
export interface ImagePuzzlePlayerCallbacks {
  readonly onReady?: ((payload: PlayerEventMap['ready']) => void) | undefined;
  readonly onMove?: ((payload: PlayerEventMap['move']) => void) | undefined;
  readonly onStateChange?: ((payload: PlayerEventMap['state-change']) => void) | undefined;
  readonly onProgress?: ((payload: PlayerEventMap['progress']) => void) | undefined;
  readonly onComplete?: ((payload: PlayerEventMap['complete']) => void) | undefined;
  readonly onError?: ((payload: PlayerEventMap['error']) => void) | undefined;
}

/**
 * Player options. `definition`, `src`, `locale`, `messages`, `theme` and
 * `themeClass` apply when they change; `initialState` is restored by the first
 * load only; `controls`, `persist`, `motion`, `pauseWhenHidden`, `fetch`, `clock`,
 * `randomSeed` and `onListenerError` are read on mount (change the `key` to
 * remount with new ones).
 */
export type UseImagePuzzlePlayerOptions = PlayerBindingOptions & ImagePuzzlePlayerCallbacks;

export interface UseImagePuzzlePlayerResult<T extends HTMLElement> {
  /** Attach to the element that hosts the player. */
  readonly ref: RefObject<T | null>;
  /** The live player after mount; null during server rendering and before mount. */
  readonly player: Player | null;
}

function handlersOf(options: ImagePuzzlePlayerCallbacks): PlayerHandlers {
  return {
    ready: (payload) => options.onReady?.(payload),
    move: (payload) => options.onMove?.(payload),
    'state-change': (payload) => options.onStateChange?.(payload),
    progress: (payload) => options.onProgress?.(payload),
    complete: (payload) => options.onComplete?.(payload),
    error: (payload) => options.onError?.(payload),
  };
}

/**
 * Mounts a player in the element behind `ref` on the client and destroys it
 * on unmount. The game state lives in the player (uncontrolled): read it from
 * the callbacks or `player.exportState()`; restore it with `initialState` on a
 * new mount (for example a new `key`), `player.load(source, { state })` or
 * `player.importState()`.
 */
export function useImagePuzzlePlayer<T extends HTMLElement = HTMLDivElement>(
  options: UseImagePuzzlePlayerOptions = {},
): UseImagePuzzlePlayerResult<T> {
  const ref = useRef<T>(null);
  const latest = useRef(options);
  const [binding, setBinding] = useState<PlayerBinding | null>(null);

  // Layout effects run before the passive mount effect below: it reads the newest options.
  useLayoutEffect(() => {
    latest.current = options;
  });

  useEffect(() => {
    const target = ref.current;
    if (target === null) return undefined;
    // The source is applied by the update effect once this binding is the
    // rendered one: a Strict Mode probe mount is destroyed before it loads
    // anything, so no event fires twice.
    const created = bindPlayer(target, { ...latest.current, definition: undefined, src: undefined }, () => handlersOf(latest.current));
    setBinding(created);
    return () => {
      created.destroy();
      setBinding(null);
    };
  }, []);

  useEffect(() => {
    binding?.update(options);
  });

  return { ref, player: binding?.player ?? null };
}
