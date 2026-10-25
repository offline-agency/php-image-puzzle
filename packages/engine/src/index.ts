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
export { autoPersist, browserStorage, clearState, restoreState, saveState, storageKey } from './persistence.js';
export type { AutoPersistOptions, PersistResult, RestoreResult, StateStorage } from './persistence.js';
export { canonicalJson, sha256Hex } from './canonical.js';
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
  Rect,
  RotationBoard,
  SlidingBoard,
  StripsBoard,
  SwapBoard,
  Tile,
} from './types.js';
