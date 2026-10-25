export { createEngine } from './engine.js';
export type {
  ActionResult,
  EngineEventMap,
  EngineEventType,
  EngineListener,
  EngineOptions,
  EngineSnapshot,
  EngineStatus,
  PuzzleEngine,
  StateChangeReason,
} from './engine.js';
export {
  DEFINITION_FORMAT,
  DEFINITION_SCHEMA_ID,
  DEFINITION_VERSION,
  GENERATOR_ALGORITHM,
  definitionDocument,
  definitionId,
  parseDefinition,
  parseDefinitionJson,
} from './definition.js';
export { HISTORY_LIMIT, STATE_FORMAT, STATE_SCHEMA_ID, STATE_VERSION, parseState, parseStateJson, stateDocument } from './state.js';
export type { ParsedState } from './state.js';
export { IllegalActionError, InvalidDocumentError, PuzzleError } from './errors.js';
export type { PuzzleErrorCode } from './errors.js';
export { MAX_RANGE, MAX_SEED, Prng, fnv1a } from './prng.js';
export { availableKinds, rulesFor } from './rules/index.js';
export type { DefinitionCore, ModeRules } from './rules/index.js';
export { allowedOrientations } from './rules/rotation.js';
export { stripAxis } from './rules/strips.js';
export { playArea, renderedRect, snapDistance, trayRect } from './rules/jigsaw.js';
export { cellRect, cellSize } from './rules/grid.js';
export {
  NONOGRAM_MAX_SIDE,
  NONOGRAM_MIN_SIDE,
  NONOGRAM_STATES,
  cluesOf,
  lineClue,
  nonogramCells,
  nonogramClues,
} from './rules/nonogram.js';
export type { NonogramClues } from './rules/nonogram.js';
export { MAX_ATLAS_SIZE, MAX_RESOLUTIONS, parseMedia, resolutionImage, resolutionLength, validateMedia } from './media.js';
export { autoPersist, browserStorage, clearState, restoreState, saveState, storageKey } from './persistence.js';
export type { AutoPersistOptions, PersistResult, RestoreResult, StateStorage } from './persistence.js';
export { canonicalJson, sha256Hex } from './canonical.js';
export {
  BUILD_CONFIG_FORMAT,
  BUILD_CONFIG_VERSION,
  BUILD_KINDS,
  CUSTOM_KEYS,
  MAX_CROP,
  MAX_JIGSAW_SEED,
  PRESET_NAMES,
  PRESET_SHAPES,
  buildConfigDocument,
  buildShape,
  createBuildConfig,
  parseBuildConfig,
} from './build-config.js';
export type { BuildConfig, BuildConfigDocument, BuildKind, BuildShape, CropRect, PresetName } from './build-config.js';
export type {
  Action,
  Assistance,
  Board,
  Capabilities,
  GameDefinition,
  GameKind,
  GameStateDocument,
  JigsawBoard,
  JigsawPiece,
  NonogramBoard,
  NonogramMark,
  Media,
  PieceAtlas,
  PieceResolution,
  Rect,
  RotationBoard,
  SlidingBoard,
  StripsBoard,
  SwapBoard,
  Tile,
} from './types.js';
