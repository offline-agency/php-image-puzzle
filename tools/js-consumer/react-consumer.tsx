// Type-level consumer of the React adapter, compiled against the packed tarballs.
import { ImagePuzzlePlayer, useImagePuzzlePlayer } from '@offline-agency/image-puzzle-react';
import type { ImagePuzzlePlayerHandle, ImagePuzzlePlayerProps, Player } from '@offline-agency/image-puzzle-react';
import { StrictMode, useRef } from 'react';
import type { ReactElement } from 'react';

export function Puzzle(props: { readonly src: string; readonly saved: unknown }): ReactElement {
  const handle = useRef<ImagePuzzlePlayerHandle>(null);
  const options: ImagePuzzlePlayerProps = {
    src: props.src,
    initialState: props.saved,
    locale: 'it',
    theme: 'dark',
    themeClass: undefined,
    controls: true,
    persist: { prefix: 'demo' },
    onReady: ({ definitionId, kind }) => {
      console.log(definitionId, kind);
    },
    onComplete: ({ moves, elapsedMs }) => {
      console.log(moves, elapsedMs, handle.current?.exportState().moves);
    },
    onError: ({ code, message }) => {
      console.log(code, message);
    },
  };
  return (
    <StrictMode>
      <ImagePuzzlePlayer ref={handle} className="board" {...options} />
    </StrictMode>
  );
}

export function HookPuzzle(): ReactElement {
  const { ref, player } = useImagePuzzlePlayer<HTMLElement>({
    src: '/puzzles/swap.json',
    onMove: ({ action }) => {
      console.log(action);
    },
  });
  const live: Player | null = player;
  return <section ref={ref} data-ready={live !== null} />;
}
