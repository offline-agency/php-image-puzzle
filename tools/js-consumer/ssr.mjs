// Server-side import of every entry: must not throw nor touch DOM globals.
import assert from 'node:assert/strict';

assert.equal(typeof globalThis.HTMLElement, 'undefined');
const engine = await import('@offline-agency/image-puzzle-engine');
const player = await import('@offline-agency/image-puzzle-player');
const define = await import('@offline-agency/image-puzzle-player/define');
const bundle = await import('@offline-agency/image-puzzle-player/bundle');

assert.equal(typeof engine.createEngine, 'function');
assert.equal(typeof player.createPlayer, 'function');
assert.equal(player.defineImagePuzzlePlayer(), undefined);
assert.equal(define.ImagePuzzlePlayerElement, undefined);
assert.equal(define.ImagePuzzleConfiguratorElement, undefined);
assert.equal(player.defineImagePuzzleConfigurator(), undefined);
assert.equal(
  engine.parseBuildConfig({ format: 'image-puzzle/build-config', version: 1, kind: 'swap', preset: 'easy', seed: 0 }).kind,
  'swap',
);
assert.equal(typeof bundle.createPlayer, 'function');
console.log('SSR imports OK');

// Framework adapters render their container on the server and stay inert.
const { createElement } = await import('react');
const { renderToString: renderReact } = await import('react-dom/server');
const react = await import('@offline-agency/image-puzzle-react');
assert.equal(
  renderReact(createElement(react.ImagePuzzlePlayer, { src: '/puzzles/swap.json', className: 'board' })),
  '<div class="board"></div>',
);

const { createSSRApp, h } = await import('vue');
const { renderToString: renderVue } = await import('vue/server-renderer');
const vue = await import('@offline-agency/image-puzzle-vue');
assert.equal(
  await renderVue(createSSRApp({ render: () => h(vue.ImagePuzzlePlayer, { src: '/puzzles/swap.json', class: 'board' }) })),
  '<div class="board"></div>',
);
console.log('Adapter SSR OK');
