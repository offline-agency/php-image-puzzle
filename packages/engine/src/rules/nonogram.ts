import * as doc from '../doc.js';
import { IllegalActionError, InvalidDocumentError } from '../errors.js';
import type { Action, Board, NonogramMark } from '../types.js';
import { actionOf } from './grid.js';
import type { DefinitionCore, ModeRules } from './types.js';

/**
 * Nonogram (src/Game/Rules/NonogramRules.php): fill cells so every row and
 * column shows its clue. Complete when the filled cells satisfy every clue,
 * so any grid matching the clues completes a non-unique puzzle.
 */

const TYPES = ['mark'] as const;
export const NONOGRAM_MIN_SIDE = 2;
export const NONOGRAM_MAX_SIDE = 30;
export const NONOGRAM_STATES: Readonly<Record<NonogramMark, string>> = { filled: '1', crossed: 'x', unknown: '.' };
const UNIQUENESS = ['unique', 'multiple', 'unverified'];
const MAX_CELLS = NONOGRAM_MAX_SIDE ** 2;

export interface NonogramClues {
  readonly rows: readonly (readonly number[])[];
  readonly cols: readonly (readonly number[])[];
}

/** Run lengths of the filled cells of one line. */
export function lineClue(line: readonly boolean[]): number[] {
  const clue: number[] = [];
  let run = 0;
  for (const filled of line) {
    if (filled) {
      run++;
    } else if (run > 0) {
      clue.push(run);
      run = 0;
    }
  }
  if (run > 0) clue.push(run);
  return clue;
}

/** Row and column clues of a row-major grid where "1" is filled. */
export function cluesOf(grid: string, rows: number, cols: number): NonogramClues {
  const filled = (r: number, c: number): boolean => grid[r * cols + c] === '1';
  return {
    rows: Array.from({ length: rows }, (_, r) => lineClue(Array.from({ length: cols }, (_, c) => filled(r, c)))),
    cols: Array.from({ length: cols }, (_, c) => lineClue(Array.from({ length: rows }, (_, r) => filled(r, c)))),
  };
}

/** The clues stored in a definition. */
export function nonogramClues(definition: DefinitionCore): NonogramClues {
  const clues = doc.object(definition.rules['clues'], 'definition.rules.clues');
  return {
    rows: clueLists(clues['rows'], definition.rows, definition.cols, 'rows'),
    cols: clueLists(clues['cols'], definition.cols, definition.rows, 'cols'),
  };
}

function clueLists(value: unknown, count: number, length: number, axis: string): number[][] {
  return doc
    .list(value, `definition.rules.clues.${axis}`, count)
    .map((line, i) =>
      doc
        .list(line, `definition.rules.clues.${axis}[${i}]`, length)
        .map((run, j) => doc.intValue(run, `definition.rules.clues.${axis}[${i}][${j}]`, 1, length)),
    );
}

function solution(definition: DefinitionCore): string {
  const value = doc.stringValue(definition.rules['solution'], 'definition.rules.solution', MAX_CELLS);
  if (value.length !== definition.rows * definition.cols || !/^[01]+$/.test(value)) {
    throw new InvalidDocumentError('definition.rules.solution must have one "1" or "0" per cell.');
  }
  return value;
}

/** Cells of a nonogram board ("1" filled, "x" crossed, "." unknown). */
export function nonogramCells(board: Board): string {
  return doc.stringValue((board as unknown as doc.JsonObject)['cells'], 'board.cells', MAX_CELLS);
}

const filledOnly = (value: string): string => value.replace(/[x.]/g, '0');

const sameLine = (a: readonly number[], b: readonly number[] | undefined): boolean =>
  b !== undefined && a.length === b.length && a.every((run, i) => run === b[i]);

const sameClues = (a: readonly (readonly number[])[], b: readonly (readonly number[])[]): boolean =>
  a.length === b.length && a.every((line, i) => sameLine(line, b[i]));

export const nonogramRules: ModeRules = {
  kind: 'nonogram',
  actionTypes: TYPES,
  capabilities: { undo: true, redo: true, reset: true, shuffle: false, hint: true },

  validateDefinition(definition) {
    const { rows, cols, crop } = definition;
    if (rows < NONOGRAM_MIN_SIDE || cols < NONOGRAM_MIN_SIDE || rows > NONOGRAM_MAX_SIDE || cols > NONOGRAM_MAX_SIDE) {
      throw new InvalidDocumentError(
        `A nonogram must be between ${NONOGRAM_MIN_SIDE}x${NONOGRAM_MIN_SIDE} and ${NONOGRAM_MAX_SIDE}x${NONOGRAM_MAX_SIDE} cells, got ${rows}x${cols}.`,
      );
    }
    if (definition.tiles.length !== 0) {
      throw new InvalidDocumentError('A nonogram has no tiles.');
    }
    if (crop.width % cols !== 0 || crop.height % rows !== 0 || Math.floor(crop.width / cols) !== Math.floor(crop.height / rows)) {
      throw new InvalidDocumentError('board.crop must divide into square cells.');
    }
    const rules = definition.rules;
    doc.keys(rules, ['solution', 'clues', 'uniqueness', 'threshold', 'invert'], 'definition.rules');
    const grid = solution(definition);
    if (!grid.includes('1')) {
      throw new InvalidDocumentError('definition.rules.solution has no filled cell.');
    }
    doc.keys(doc.object(rules['clues'], 'definition.rules.clues'), ['rows', 'cols'], 'definition.rules.clues');
    const stored = nonogramClues(definition);
    const expected = cluesOf(grid, rows, cols);
    if (!sameClues(stored.rows, expected.rows) || !sameClues(stored.cols, expected.cols)) {
      throw new InvalidDocumentError('definition.rules.clues do not match rules.solution.');
    }
    if (typeof rules['uniqueness'] !== 'string' || !UNIQUENESS.includes(rules['uniqueness'])) {
      throw new InvalidDocumentError('definition.rules.uniqueness must be unique, multiple or unverified.');
    }
    doc.int(rules, 'threshold', 'definition.rules', 1, 255);
    doc.bool(rules, 'invert', 'definition.rules');
  },

  solvedBoard(definition) {
    return { cells: solution(definition).replace(/0/g, 'x') };
  },

  /** Every cell unknown: the seed does not change a nonogram. */
  scramble(definition) {
    return { cells: '.'.repeat(definition.rows * definition.cols) };
  },

  validateBoard(definition, value) {
    const board = doc.object(value, 'board');
    doc.keys(board, ['cells'], 'board');
    const text = doc.stringValue(board['cells'], 'board.cells', MAX_CELLS);
    if (text.length !== definition.rows * definition.cols || !/^[1x.]*$/.test(text)) {
      throw new InvalidDocumentError('board.cells must have one of "1", "x" or "." per cell.');
    }
    return { cells: text };
  },

  apply(definition, board, value) {
    const action = actionOf('nonogram', TYPES, value, ['cell', 'state']);
    const count = definition.rows * definition.cols;
    const cell = doc.int(action, 'cell', 'action', 0, Number.MAX_SAFE_INTEGER);
    if (cell >= count) {
      throw new IllegalActionError(`Cell ${cell} is outside the ${definition.rows}x${definition.cols} grid.`);
    }
    const state = doc.string(action, 'state', 'action', 16);
    if (!Object.prototype.hasOwnProperty.call(NONOGRAM_STATES, state)) {
      throw new InvalidDocumentError('action.state must be filled, crossed or unknown.');
    }
    const char = NONOGRAM_STATES[state as NonogramMark];
    const current = nonogramCells(board);
    if (current[cell] === char) {
      throw new IllegalActionError(`Cell ${cell} is already ${state}.`);
    }
    return { cells: current.slice(0, cell) + char + current.slice(cell + 1) };
  },

  legalActions(_definition, board): Action[] {
    const current = nonogramCells(board);
    const states = Object.entries(NONOGRAM_STATES) as [NonogramMark, string][];
    const out: Action[] = [];
    for (let i = 0; i < current.length; i++) {
      for (const [state, char] of states) {
        if (current[i] !== char) out.push({ type: 'mark', cell: i, state });
      }
    }
    return out;
  },

  isComplete(definition, board) {
    const actual = cluesOf(filledOnly(nonogramCells(board)), definition.rows, definition.cols);
    const expected = nonogramClues(definition);
    return sameClues(actual.rows, expected.rows) && sameClues(actual.cols, expected.cols);
  },

  /** Fraction of rows and columns whose filled cells already match their clue. */
  progress(definition, board) {
    const actual = cluesOf(filledOnly(nonogramCells(board)), definition.rows, definition.cols);
    const expected = nonogramClues(definition);
    const matching =
      actual.rows.filter((clue, i) => sameLine(clue, expected.rows[i])).length +
      actual.cols.filter((clue, i) => sameLine(clue, expected.cols[i])).length;
    return matching / (definition.rows + definition.cols);
  },

  /**
   * Marks the first cell (row-major) that disagrees with the generated
   * solution: a missing fill, or a filled cell that should be empty.
   */
  hint(definition, board) {
    if (nonogramRules.isComplete(definition, board)) return null;
    const current = nonogramCells(board);
    const grid = solution(definition);
    for (let i = 0; i < grid.length; i++) {
      if (grid[i] === '1' && current[i] !== '1') return { type: 'mark', cell: i, state: 'filled' };
      if (grid[i] === '0' && current[i] === '1') return { type: 'mark', cell: i, state: 'crossed' };
    }
    return null;
  },
};
