// Type-level consumer of the Vue adapter, compiled against the packed tarballs.
import { ImagePuzzlePlayer, useImagePuzzlePlayer } from '@offline-agency/image-puzzle-vue';
import type { ImagePuzzlePlayerHandle, Player } from '@offline-agency/image-puzzle-vue';
import { defineComponent, h, ref } from 'vue';

export const Puzzle = defineComponent({
  setup() {
    const handle = ref<ImagePuzzlePlayerHandle | null>(null);
    return () =>
      h(ImagePuzzlePlayer, {
        ref: (instance: unknown) => {
          handle.value = instance as ImagePuzzlePlayerHandle | null;
        },
        src: '/puzzles/jigsaw.json',
        locale: 'it',
        theme: 'dark',
        controls: true,
        onReady: ({ definitionId }: { definitionId: string }) => {
          console.log(definitionId);
        },
        onComplete: ({ moves }: { moves: number }) => {
          console.log(moves, handle.value?.exportState().moves);
        },
      });
  },
});

export const HookPuzzle = defineComponent({
  setup() {
    const target = ref<HTMLElement | null>(null);
    const { player } = useImagePuzzlePlayer(target, () => ({ src: '/puzzles/swap.json' }), {
      move: ({ action }) => {
        console.log(action);
      },
    });
    return () => h('section', { ref: target, 'data-ready': (player.value satisfies Player | null) !== null });
  },
});
