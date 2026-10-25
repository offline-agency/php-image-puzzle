// Declarations for the no-bundler build: <script src="dist/image-puzzle-player.iife.js">
// defines the global `ImagePuzzle`. Reference with
// import type {} from '@offline-agency/image-puzzle-player/global';
declare global {
  const ImagePuzzle: typeof import('./dist/browser.js');
  interface Window {
    ImagePuzzle: typeof import('./dist/browser.js');
  }
}

export {};
