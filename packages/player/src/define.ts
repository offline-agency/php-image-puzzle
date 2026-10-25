/**
 * Side-effect entry: registers <image-puzzle-player> when imported in a
 * browser. Safe to import more than once and during SSR (it does nothing
 * without customElements).
 */
import { defineImagePuzzlePlayer } from './element.js';

export const ImagePuzzlePlayerElement = defineImagePuzzlePlayer();
export { DEFAULT_TAG, ELEMENT_EVENTS, defineImagePuzzlePlayer } from './element.js';
export type { ElementOptions } from './element.js';
