import * as doc from './doc.js';
import { InvalidDocumentError } from './errors.js';
import { rulesFor } from './rules/index.js';
import type { Board, GameDefinition, GameStateDocument } from './types.js';

export const STATE_FORMAT = 'image-puzzle/game-state';
export const STATE_VERSION = 1;
export const HISTORY_LIMIT = 100;
export const MAX_STATE_BYTES = 8 * 1024 * 1024;
export const STATE_SCHEMA_ID =
  'https://raw.githubusercontent.com/offline-agency/php-image-puzzle/HEAD/docs/schemas/game/v1/state.schema.json';

export interface ParsedState {
  readonly board: Board;
  readonly moves: number;
  readonly elapsedMs: number;
  readonly hintsUsed: number;
  readonly completed: boolean;
  readonly past: readonly Board[];
  readonly future: readonly Board[];
}

export function parseStateJson(definition: GameDefinition, json: string): ParsedState {
  if (new TextEncoder().encode(json).length > MAX_STATE_BYTES) {
    throw new InvalidDocumentError(`Game state exceeds ${MAX_STATE_BYTES} bytes.`);
  }
  let data: unknown;
  try {
    data = JSON.parse(json);
  } catch (error) {
    throw new InvalidDocumentError(`Game state is not valid JSON: ${error instanceof Error ? error.message : String(error)}`);
  }
  return parseState(definition, data);
}

/** Validates an imported state like GameState::fromArray() in PHP. */
export function parseState(definition: GameDefinition, data: unknown): ParsedState {
  const root = doc.object(data, 'state');
  doc.keys(
    root,
    ['$schema', 'format', 'version', 'definitionId', 'kind', 'board', 'moves', 'elapsedMs', 'hintsUsed', 'completed', 'history'],
    'state',
  );
  if (root['format'] !== STATE_FORMAT) {
    throw new InvalidDocumentError(`state.format must be "${STATE_FORMAT}".`);
  }
  const version = doc.int(root, 'version', 'state', 1);
  if (version !== STATE_VERSION) {
    throw new InvalidDocumentError(`Unsupported game state version ${version}; this library reads version ${STATE_VERSION}.`);
  }
  if (doc.string(root, 'definitionId', 'state', 80) !== definition.id) {
    throw new InvalidDocumentError('state.definitionId belongs to another puzzle.');
  }
  if (doc.string(root, 'kind', 'state', 32) !== definition.kind) {
    throw new InvalidDocumentError('state.kind does not match the definition.');
  }
  const rules = rulesFor(definition.kind);
  const board = rules.validateBoard(definition, root['board']);
  const completed = doc.bool(root, 'completed', 'state');
  if (completed !== rules.isComplete(definition, board)) {
    throw new InvalidDocumentError('state.completed does not match the board.');
  }
  const history = doc.object(root['history'], 'state.history');
  doc.keys(history, ['past', 'future'], 'state.history');
  const past = doc.list(history['past'], 'state.history.past', HISTORY_LIMIT).map((entry) => rules.validateBoard(definition, entry));
  const future = doc.list(history['future'], 'state.history.future', HISTORY_LIMIT).map((entry) => rules.validateBoard(definition, entry));

  return {
    board,
    moves: doc.int(root, 'moves', 'state', 0),
    elapsedMs: doc.int(root, 'elapsedMs', 'state', 0),
    hintsUsed: doc.int(root, 'hintsUsed', 'state', 0),
    completed,
    past,
    future,
  };
}

export function stateDocument(definition: GameDefinition, state: ParsedState): GameStateDocument {
  return structuredClone({
    $schema: STATE_SCHEMA_ID,
    format: STATE_FORMAT,
    version: STATE_VERSION,
    definitionId: definition.id,
    kind: definition.kind,
    board: state.board,
    moves: state.moves,
    elapsedMs: state.elapsedMs,
    hintsUsed: state.hintsUsed,
    completed: state.completed,
    history: { past: state.past, future: state.future },
  } as const);
}
