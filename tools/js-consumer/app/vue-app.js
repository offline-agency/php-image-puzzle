import { ImagePuzzlePlayer } from '@offline-agency/image-puzzle-vue';
import { createApp, defineComponent, h, ref, shallowRef } from 'vue';

const SOURCES = ['puzzles/swap.json', 'puzzles/jigsaw.json'];

const App = defineComponent({
  setup() {
    const handle = shallowRef(null);
    const source = ref(0);
    const mounted = ref(true);
    const saved = shallowRef(null);
    const generation = ref(0);
    const events = ref([]);
    const log = (entry) => {
      events.value = [...events.value, entry];
    };
    window.puzzles.vue = () => handle.value?.player ?? null;

    return () =>
      h('section', { 'aria-label': 'Vue', 'data-framework': 'vue' }, [
        h('h2', 'Vue'),
        h(
          'button',
          {
            type: 'button',
            onClick: () => {
              // A save belongs to its puzzle.
              saved.value = null;
              source.value = 1 - source.value;
            },
          },
          'Switch puzzle',
        ),
        h('button', { type: 'button', onClick: () => (mounted.value = !mounted.value) }, mounted.value ? 'Unmount' : 'Mount'),
        h('button', { type: 'button', onClick: () => (saved.value = handle.value.exportState()) }, 'Save'),
        h('button', { type: 'button', onClick: () => (generation.value += 1) }, 'Restore'),
        h('output', { 'data-events': events.value.join(' ') }, events.value.join(' ')),
        mounted.value
          ? h(ImagePuzzlePlayer, {
              key: generation.value,
              ref: (instance) => {
                handle.value = instance;
              },
              src: SOURCES[source.value],
              initialState: saved.value,
              onReady: ({ kind }) => log(`ready:${kind}`),
              onComplete: () => log('complete'),
              onError: ({ code }) => log(`error:${code}`),
            })
          : null,
      ]);
  },
});

export function mountVue(element) {
  createApp(App).mount(element);
}
