import type { Action, Board, Capabilities, GameDefinition, GameKind } from '../types.js';

/** The immutable part of a definition the rules need (no scramble yet). */
export type DefinitionCore = Omit<GameDefinition, 'id' | 'initial' | 'actions' | 'capabilities'>;

/**
 * Pure rules of one mode, mirroring src/Game/Rules/ModeRules.php. Boards
 * passed in are the ones returned by validateBoard/solvedBoard/scramble/apply;
 * actions are external data validated by apply().
 */
export interface ModeRules {
  readonly kind: GameKind;
  readonly actionTypes: readonly string[];
  readonly capabilities: Capabilities;
  validateDefinition(definition: DefinitionCore): void;
  solvedBoard(definition: DefinitionCore): Board;
  scramble(definition: DefinitionCore, seed: number): Board;
  validateBoard(definition: DefinitionCore, board: unknown): Board;
  apply(definition: DefinitionCore, board: Board, action: unknown): Board;
  legalActions(definition: DefinitionCore, board: Board): Action[];
  isComplete(definition: DefinitionCore, board: Board): boolean;
  progress(definition: DefinitionCore, board: Board): number;
  hint(definition: DefinitionCore, board: Board): Action | null;
}
