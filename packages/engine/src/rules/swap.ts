import * as doc from '../doc.js';
import { IllegalActionError, InvalidDocumentError } from '../errors.js';
import { Prng } from '../prng.js';
import type { Action } from '../types.js';
import { actionOf, permutation, sameList, shuffledPermutation, stringList, tileIds, validateGrid } from './grid.js';
import type { ModeRules } from './types.js';

const TYPES = ['swap'] as const;

export const swapRules: ModeRules = {
  kind: 'swap',
  actionTypes: TYPES,
  capabilities: { undo: true, redo: true, reset: true, shuffle: true, hint: true },

  validateDefinition(definition) {
    validateGrid(definition, 1, 1, 16);
    doc.keys(definition.rules, [], 'definition.rules');
  },

  solvedBoard(definition) {
    return { cells: tileIds(definition) };
  },

  scramble(definition, seed) {
    return { cells: shuffledPermutation(tileIds(definition), new Prng(seed, 'image-puzzle/scramble/swap')) };
  },

  validateBoard(definition, value) {
    const board = doc.object(value, 'board');
    doc.keys(board, ['cells'], 'board');
    return { cells: permutation(board['cells'], tileIds(definition), 'board.cells') };
  },

  apply(_definition, board, value) {
    const action = actionOf('swap', TYPES, value, ['a', 'b']);
    const a = doc.id(action['a'], 'action.a');
    const b = doc.id(action['b'], 'action.b');
    if (a === b) {
      throw new IllegalActionError('A swap needs two different tiles.');
    }
    const cells = stringList(board, 'cells');
    const i = cells.indexOf(a);
    const j = cells.indexOf(b);
    if (i < 0 || j < 0) {
      throw new IllegalActionError('Both tiles of a swap must be on the board.');
    }
    cells[i] = b;
    cells[j] = a;
    return { cells };
  },

  legalActions(_definition, board): Action[] {
    const cells = stringList(board, 'cells');
    const out: Action[] = [];
    for (let i = 0; i < cells.length; i++) {
      for (let j = i + 1; j < cells.length; j++) {
        out.push({ type: 'swap', a: cells[i] as string, b: cells[j] as string });
      }
    }
    return out;
  },

  isComplete(definition, board) {
    return sameList(stringList(board, 'cells'), tileIds(definition));
  },

  progress(definition, board) {
    const ids = tileIds(definition);
    return stringList(board, 'cells').filter((id, i) => id === ids[i]).length / Math.max(1, ids.length);
  },

  hint(definition, board) {
    const cells = stringList(board, 'cells');
    const ids = tileIds(definition);
    for (let i = 0; i < ids.length; i++) {
      const current = cells[i];
      if (current === undefined) {
        throw new InvalidDocumentError('board.cells is incomplete.');
      }
      if (current !== ids[i]) {
        return { type: 'swap', a: current, b: ids[i] as string };
      }
    }
    return null;
  },
};
