import { bindPlayer } from '@offline-agency/image-puzzle-player';
import type { Player, PlayerBinding, PlayerBindingOptions, PlayerHandlers } from '@offline-agency/image-puzzle-player';
import { onBeforeUnmount, onMounted, shallowRef, toValue, watch } from 'vue';
import type { MaybeRefOrGetter, Ref, ShallowRef } from 'vue';

export interface UseImagePuzzlePlayerResult {
  /** The live player after mount; null during server rendering and before mount. */
  readonly player: Readonly<ShallowRef<Player | null>>;
}

/**
 * Mounts a player in `target` when the component mounts and destroys it
 * before unmount. `options` is read reactively: a new `definition` or `src`
 * loads it (the first load restores `initialState`), `locale`, `messages`, `theme` and
 * `themeClass` apply live, and the creation options (`controls`, `persist`,
 * `motion`, ...) are read on mount. `handlers` are read on every event.
 */
export function useImagePuzzlePlayer(
  target: Readonly<Ref<HTMLElement | null>>,
  options: MaybeRefOrGetter<PlayerBindingOptions>,
  handlers: MaybeRefOrGetter<PlayerHandlers> = {},
): UseImagePuzzlePlayerResult {
  const player = shallowRef<Player | null>(null);
  let binding: PlayerBinding | null = null;

  onMounted(() => {
    const element = target.value;
    if (element === null) return;
    binding = bindPlayer(element, toValue(options), () => toValue(handlers));
    player.value = binding.player;
  });

  watch(
    // Spreading reads every top-level option, so reactive objects are tracked too (not deeply:
    // definitions are compared by identity).
    () => ({ ...toValue(options) }),
    (next) => {
      binding?.update(next);
    },
  );

  onBeforeUnmount(() => {
    binding?.destroy();
    binding = null;
    player.value = null;
  });

  return { player };
}
