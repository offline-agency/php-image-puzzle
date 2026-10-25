import type { LoadOptions, Player } from '@offline-agency/image-puzzle-player';
import { useImperativeHandle } from 'react';
import type { CSSProperties, ReactElement, Ref } from 'react';
import { useImagePuzzlePlayer } from './use-image-puzzle-player.js';
import type { UseImagePuzzlePlayerOptions } from './use-image-puzzle-player.js';

/** Imperative operations, through `ref`. They throw while the player is not mounted. */
export interface ImagePuzzlePlayerHandle {
  /** The live player, or null before mount. */
  readonly player: Player | null;
  /** Loads another definition object or URL, optionally with a saved state. */
  load(source: unknown, options?: LoadOptions): Promise<void>;
  /** Restarts the current puzzle from its initial arrangement. */
  reset(): void;
  shuffle(seed?: number): void;
  exportState(): ReturnType<Player['exportState']>;
  importState(state: unknown): void;
}

export type ImagePuzzlePlayerProps = UseImagePuzzlePlayerOptions & {
  readonly id?: string | undefined;
  readonly className?: string | undefined;
  readonly style?: CSSProperties | undefined;
  readonly ref?: Ref<ImagePuzzlePlayerHandle> | undefined;
};

function mounted(player: Player | null): Player {
  if (player === null) throw new Error('The image puzzle player is not mounted.');
  return player;
}

/**
 * Plays an image-puzzle definition: renders a container element and mounts
 * the shared player into it on the client.
 *
 *     <ImagePuzzlePlayer src="/puzzles/jigsaw.json" locale="it" onComplete={({ moves }) => save(moves)} />
 */
export function ImagePuzzlePlayer({ id, className, style, ref, ...options }: ImagePuzzlePlayerProps): ReactElement {
  const { ref: container, player } = useImagePuzzlePlayer(options);

  useImperativeHandle(
    ref,
    (): ImagePuzzlePlayerHandle => ({
      player,
      load: (source, loadOptions) => mounted(player).load(source, loadOptions),
      reset: () => {
        mounted(player).reset();
      },
      shuffle: (seed) => {
        mounted(player).shuffle(seed);
      },
      exportState: () => mounted(player).exportState(),
      importState: (state) => {
        mounted(player).importState(state);
      },
    }),
    [player],
  );

  return <div ref={container} id={id} className={className} style={style} />;
}
