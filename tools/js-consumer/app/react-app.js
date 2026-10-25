import { ImagePuzzlePlayer } from '@offline-agency/image-puzzle-react';
import { StrictMode, createElement as h, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';

const SOURCES = ['puzzles/swap.json', 'puzzles/jigsaw.json'];

function App() {
  const handle = useRef(null);
  const [source, setSource] = useState(0);
  const [mounted, setMounted] = useState(true);
  const [saved, setSaved] = useState(null);
  const [generation, setGeneration] = useState(0);
  const [events, setEvents] = useState([]);
  const log = (entry) => {
    setEvents((list) => [...list, entry]);
  };
  window.puzzles.react = () => handle.current?.player ?? null;

  return h(
    'section',
    { 'aria-label': 'React', 'data-framework': 'react' },
    h('h2', null, 'React'),
    h(
      'button',
      {
        type: 'button',
        onClick: () => {
          // A save belongs to its puzzle.
          setSaved(null);
          setSource((index) => 1 - index);
        },
      },
      'Switch puzzle',
    ),
    h('button', { type: 'button', onClick: () => setMounted((value) => !value) }, mounted ? 'Unmount' : 'Mount'),
    h('button', { type: 'button', onClick: () => setSaved(handle.current.exportState()) }, 'Save'),
    h('button', { type: 'button', onClick: () => setGeneration((value) => value + 1) }, 'Restore'),
    h('output', { 'data-events': events.join(' ') }, events.join(' ')),
    mounted
      ? h(ImagePuzzlePlayer, {
          key: generation,
          ref: handle,
          src: SOURCES[source],
          initialState: saved,
          onReady: ({ kind }) => log(`ready:${kind}`),
          onComplete: () => log('complete'),
          onError: ({ code }) => log(`error:${code}`),
        })
      : null,
  );
}

export function mountReact(element) {
  createRoot(element).render(h(StrictMode, null, h(App)));
}
