import type { LoadOptions, Messages, PersistOptions, Player, PlayerEventMap, PlayerOptions } from '@offline-agency/image-puzzle-player';
import { defineComponent, h, ref } from 'vue';
import type { PropType } from 'vue';
import { useImagePuzzlePlayer } from './use-image-puzzle-player.js';

/** Imperative operations, through a template ref. They throw while the player is not mounted. */
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

const isPayload = (payload: unknown): boolean => typeof payload === 'object' && payload !== null;

/**
 * Plays an image-puzzle definition: renders a container element and mounts
 * the shared player into it on the client.
 *
 *     <ImagePuzzlePlayer src="/puzzles/jigsaw.json" locale="it" @complete="({ moves }) => save(moves)" />
 */
export const ImagePuzzlePlayer = defineComponent({
  name: 'ImagePuzzlePlayer',
  props: {
    /** Definition document; wins over `src`. Compared by identity: keep it in a shallowRef or computed. */
    definition: { type: Object as PropType<object>, default: undefined },
    /** Definition URL. */
    src: { type: String, default: undefined },
    /** Saved state restored by the first load after mount; later sources start fresh games. */
    initialState: { type: Object as PropType<object | null>, default: undefined },
    baseUrl: { type: String, default: undefined },
    locale: { type: String, default: undefined },
    messages: { type: Object as PropType<Partial<Messages>>, default: undefined },
    theme: { type: String as PropType<'light' | 'dark'>, default: undefined },
    themeClass: { type: String, default: undefined },
    // Read on mount; change the component `key` to remount with new values.
    controls: { type: Boolean, default: true },
    persist: { type: [Boolean, Object] as PropType<boolean | PersistOptions>, default: false },
    motion: { type: String as PropType<'auto' | 'reduce' | 'full'>, default: undefined },
    pauseWhenHidden: { type: Boolean, default: true },
    fetch: { type: Function as PropType<PlayerOptions['fetch']>, default: undefined },
    clock: { type: Function as PropType<PlayerOptions['clock']>, default: undefined },
    randomSeed: { type: Function as PropType<PlayerOptions['randomSeed']>, default: undefined },
    onListenerError: { type: Function as PropType<PlayerOptions['onListenerError']>, default: undefined },
  },
  emits: {
    ready: (payload: PlayerEventMap['ready']) => isPayload(payload),
    move: (payload: PlayerEventMap['move']) => isPayload(payload),
    'state-change': (payload: PlayerEventMap['state-change']) => isPayload(payload),
    progress: (payload: PlayerEventMap['progress']) => isPayload(payload),
    complete: (payload: PlayerEventMap['complete']) => isPayload(payload),
    error: (payload: PlayerEventMap['error']) => isPayload(payload),
  },
  setup(props, { emit, expose }) {
    const root = ref<HTMLElement | null>(null);
    const { player } = useImagePuzzlePlayer(root, () => props, {
      ready: (payload) => {
        emit('ready', payload);
      },
      move: (payload) => {
        emit('move', payload);
      },
      'state-change': (payload) => {
        emit('state-change', payload);
      },
      progress: (payload) => {
        emit('progress', payload);
      },
      complete: (payload) => {
        emit('complete', payload);
      },
      error: (payload) => {
        emit('error', payload);
      },
    });

    const mounted = (): Player => {
      if (player.value === null) throw new Error('The image puzzle player is not mounted.');
      return player.value;
    };
    const handle: ImagePuzzlePlayerHandle = {
      get player() {
        return player.value;
      },
      load: (source, options) => mounted().load(source, options),
      reset: () => {
        mounted().reset();
      },
      shuffle: (seed) => {
        mounted().shuffle(seed);
      },
      exportState: () => mounted().exportState(),
      importState: (state) => {
        mounted().importState(state);
      },
    };
    expose(handle);

    return () => h('div', { ref: root });
  },
});
