/**
 * No-bundler browser build (dist/image-puzzle-player.iife.js): exposes the
 * global `ImagePuzzle` and registers <image-puzzle-player> and
 * <image-puzzle-configurator>.
 */
import { defineImagePuzzleConfigurator } from './configurator-element.js';
import { defineImagePuzzlePlayer } from './element.js';

export * from './index.js';
export {
  HISTORY_LIMIT,
  IllegalActionError,
  InvalidDocumentError,
  PuzzleError,
  createEngine,
  buildConfigDocument,
  parseBuildConfig,
  parseDefinition,
} from '@offline-agency/image-puzzle-engine';

defineImagePuzzlePlayer();
defineImagePuzzleConfigurator();
