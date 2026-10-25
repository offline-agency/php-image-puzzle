/**
 * No-bundler browser build (dist/image-puzzle-player.iife.js): exposes the
 * global `ImagePuzzle` and registers <image-puzzle-player>.
 */
import { defineImagePuzzlePlayer } from './element.js';

export * from './index.js';
export {
  HISTORY_LIMIT,
  IllegalActionError,
  InvalidDocumentError,
  PuzzleError,
  createEngine,
  parseDefinition,
} from '@offline-agency/image-puzzle-engine';

defineImagePuzzlePlayer();
