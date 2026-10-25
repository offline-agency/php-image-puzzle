/**
 * Side-effect entry: registers <image-puzzle-player> and
 * <image-puzzle-configurator> when imported in a browser. Safe to import more
 * than once and during SSR (it does nothing without customElements).
 */
import { defineImagePuzzleConfigurator } from './configurator-element.js';
import { defineImagePuzzlePlayer } from './element.js';

export const ImagePuzzlePlayerElement = defineImagePuzzlePlayer();
export const ImagePuzzleConfiguratorElement = defineImagePuzzleConfigurator();
export { CONFIGURATOR_TAG, defineImagePuzzleConfigurator } from './configurator-element.js';
export { DEFAULT_TAG, ELEMENT_EVENTS, defineImagePuzzlePlayer } from './element.js';
export type { ElementOptions } from './element.js';
