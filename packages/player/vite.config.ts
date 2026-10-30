import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite';

/*
 * Single-file browser builds bundling the engine: an ES module for
 * <script type="module"> without an import map, and an IIFE exposing the
 * global `ImagePuzzle` for a plain <script>. The per-module ESM build with
 * declarations is produced by tsc (tsconfig.build.json).
 */
export default defineConfig({
  build: {
    outDir: 'dist',
    emptyOutDir: false,
    target: 'es2022',
    sourcemap: true,
    minify: true,
    lib: {
      entry: fileURLToPath(new URL('src/browser.ts', import.meta.url)),
      name: 'ImagePuzzle',
      formats: ['es', 'iife'],
      fileName: (format) => (format === 'es' ? 'image-puzzle-player.bundle.js' : 'image-puzzle-player.iife.js'),
    },
  },
});
