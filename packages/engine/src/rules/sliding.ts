import * as doc from '../doc.js';
import { IllegalActionError, InvalidDocumentError } from '../errors.js';
import { Prng } from '../prng.js';
import type { Action, Board, SlidingBoard } from '../types.js';
import { actionOf, permutation, tileIds, validateGrid } from './grid.js';
import type { DefinitionCore, ModeRules } from './types.js';

const MAX_SIDE = 16;
const MAX_SCRAMBLE_MOVES = 100000;
const TYPES = ['slide'] as const;

function emptyTile(definition: DefinitionCore): string {
  return doc.id(definition.rules['empty'], 'definition.rules.empty');
}

function cells(board: Board): (string | null)[] {
  const items = doc.list((board as unknown as doc.JsonObject)['cells'], 'board.cells', 4096);
  return items.map((cell) => (cell === null ? null : doc.stringValue(cell, 'board.cells[]', 32)));
}

function gap(list: readonly (string | null)[]): number {
  const index = list.indexOf(null);
  if (index < 0) {
    throw new InvalidDocumentError('board.cells has no empty cell.');
  }
  return index;
}

/** Orthogonal neighbours in ascending index order: up, left, right, down. */
function neighbours(definition: DefinitionCore, cell: number): number[] {
  const { cols, rows } = definition;
  const row = Math.floor(cell / cols);
  const col = cell % cols;
  const out: number[] = [];
  if (row > 0) out.push(cell - cols);
  if (col > 0) out.push(cell - 1);
  if (col < cols - 1) out.push(cell + 1);
  if (row < rows - 1) out.push(cell + cols);
  return out;
}

function legalTiles(definition: DefinitionCore, board: Board): string[] {
  const list = cells(board);
  const out: string[] = [];
  for (const cell of neighbours(definition, gap(list))) {
    const id = list[cell];
    if (typeof id === 'string') out.push(id);
  }
  return out;
}

function reachable(definition: DefinitionCore, list: readonly (string | null)[]): boolean {
  const empty = emptyTile(definition);
  const home = new Map(definition.tiles.map((tile) => [tile.id, tile.home]));
  const perm = list.map((id) => home.get(id ?? empty) ?? -1);
  const visited = new Set<number>();
  let transpositions = 0;
  perm.forEach((_, start) => {
    if (visited.has(start)) return;
    let length = 0;
    for (let i = start; !visited.has(i); i = perm[i] ?? start) {
      visited.add(i);
      length++;
    }
    transpositions += length - 1;
  });
  const g = gap(list);
  const target = home.get(empty) ?? 0;
  const { cols } = definition;
  const distance = Math.abs(Math.floor(g / cols) - Math.floor(target / cols)) + Math.abs((g % cols) - (target % cols));
  return transpositions % 2 === distance % 2;
}

export const slidingRules: ModeRules = {
  kind: 'sliding',
  actionTypes: TYPES,
  capabilities: { undo: true, redo: true, reset: true, shuffle: true, hint: false },

  validateDefinition(definition) {
    validateGrid(definition, 2, 2, MAX_SIDE);
    doc.keys(definition.rules, ['empty', 'scrambleMoves'], 'definition.rules');
    const empty = doc.id(definition.rules['empty'], 'definition.rules.empty');
    if (!definition.tiles.some((tile) => tile.id === empty)) {
      throw new InvalidDocumentError(`definition.rules.empty names an unknown tile "${empty}".`);
    }
    doc.int(definition.rules, 'scrambleMoves', 'definition.rules', 1, MAX_SCRAMBLE_MOVES);
  },

  solvedBoard(definition): SlidingBoard {
    const empty = emptyTile(definition);
    return { cells: tileIds(definition).map((id) => (id === empty ? null : id)) };
  },

  scramble(definition, seed) {
    const rng = new Prng(seed, 'image-puzzle/scramble/sliding');
    let board: Board = this.solvedBoard(definition);
    const solved = JSON.stringify(board);
    const moves = doc.int(definition.rules, 'scrambleMoves', 'definition.rules', 1, MAX_SCRAMBLE_MOVES);
    let previous: string | null = null;
    const limit = moves + 4 * definition.tiles.length;
    for (let step = 0; step < limit && (step < moves || JSON.stringify(board) === solved); step++) {
      const candidates = legalTiles(definition, board).filter((id) => id !== previous);
      previous = candidates[rng.int(candidates.length)] ?? null;
      board = this.apply(definition, board, { type: 'slide', tile: previous });
    }
    if (JSON.stringify(board) === solved) {
      throw new InvalidDocumentError('Could not produce an unsolved sliding scramble.');
    }
    return board;
  },

  validateBoard(definition, value) {
    const board = doc.object(value, 'board');
    doc.keys(board, ['cells'], 'board');
    const list = doc.list(board['cells'], 'board.cells', 4096);
    const count = definition.tiles.length;
    if (list.length !== count) {
      throw new InvalidDocumentError(`board.cells must have ${count} entries, got ${list.length}.`);
    }
    const empty = emptyTile(definition);
    const ids = tileIds(definition).filter((id) => id !== empty);
    if (list.filter((cell) => cell === null).length !== 1) {
      throw new InvalidDocumentError('board.cells must contain exactly one empty cell (null).');
    }
    permutation(
      list.filter((cell) => cell !== null),
      ids,
      'board.cells',
    );
    const normalized = list.map((cell) => (typeof cell === 'string' ? cell : null));
    if (!reachable(definition, normalized)) {
      throw new InvalidDocumentError('board.cells is not reachable by legal slides from the solved board.');
    }
    return { cells: normalized };
  },

  apply(definition, board, value) {
    const action = actionOf('sliding', TYPES, value, ['tile']);
    const tile = doc.id(action['tile'], 'action.tile');
    const list = cells(board);
    const from = list.indexOf(tile);
    if (from < 0) {
      throw new IllegalActionError(`Tile "${tile}" is not on the board.`);
    }
    const g = gap(list);
    if (!neighbours(definition, g).includes(from)) {
      throw new IllegalActionError(`Tile "${tile}" is not next to the empty cell.`);
    }
    list[g] = tile;
    list[from] = null;
    return { cells: list };
  },

  legalActions(definition, board): Action[] {
    return legalTiles(definition, board).map((tile) => ({ type: 'slide', tile }));
  },

  isComplete(definition, board) {
    return JSON.stringify(cells(board)) === JSON.stringify(cells(this.solvedBoard(definition)));
  },

  progress(definition, board) {
    const list = cells(board);
    const solved = cells(this.solvedBoard(definition));
    const correct = list.filter((id, i) => id !== null && id === solved[i]).length;
    return correct / Math.max(1, list.length - 1);
  },

  hint() {
    return null;
  },
};
