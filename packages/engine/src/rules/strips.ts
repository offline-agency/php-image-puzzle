import * as doc from '../doc.js';
import { IllegalActionError, InvalidDocumentError } from '../errors.js';
import { Prng } from '../prng.js';
import type { Action } from '../types.js';
import { actionOf, permutation, sameList, shuffledPermutation, stringList, tileIds, validateGrid } from './grid.js';
import type { DefinitionCore, ModeRules } from './types.js';

const TYPES = ['move'] as const;

export function stripAxis(definition: DefinitionCore): 'horizontal' | 'vertical' {
  const axis = doc.string(definition.rules, 'axis', 'definition.rules', 16);
  if (axis !== 'horizontal' && axis !== 'vertical') {
    throw new InvalidDocumentError('definition.rules.axis must be "horizontal" or "vertical".');
  }
  return axis;
}

export const stripsRules: ModeRules = {
  kind: 'strips',
  actionTypes: TYPES,
  capabilities: { undo: true, redo: true, reset: true, shuffle: true, hint: true },

  validateDefinition(definition) {
    doc.keys(definition.rules, ['axis'], 'definition.rules');
    const axis = stripAxis(definition);
    if (axis === 'horizontal' && definition.cols !== 1) {
      throw new InvalidDocumentError('Horizontal strips need board.cols = 1.');
    }
    if (axis === 'vertical' && definition.rows !== 1) {
      throw new InvalidDocumentError('Vertical strips need board.rows = 1.');
    }
    validateGrid(definition, 1, 1, 128);
  },

  solvedBoard(definition) {
    return { order: tileIds(definition) };
  },

  scramble(definition, seed) {
    return { order: shuffledPermutation(tileIds(definition), new Prng(seed, 'image-puzzle/scramble/strips')) };
  },

  validateBoard(definition, value) {
    const board = doc.object(value, 'board');
    doc.keys(board, ['order'], 'board');
    return { order: permutation(board['order'], tileIds(definition), 'board.order') };
  },

  apply(_definition, board, value) {
    const action = actionOf('strips', TYPES, value, ['strip', 'to']);
    const strip = doc.id(action['strip'], 'action.strip');
    const order = stringList(board, 'order');
    const to = doc.int(action, 'to', 'action', 0, Math.max(0, order.length - 1));
    const from = order.indexOf(strip);
    if (from < 0) {
      throw new IllegalActionError(`Strip "${strip}" is not on the board.`);
    }
    if (from === to) {
      throw new IllegalActionError(`Strip "${strip}" is already at position ${to}.`);
    }
    order.splice(from, 1);
    order.splice(to, 0, strip);
    return { order };
  },

  legalActions(_definition, board): Action[] {
    const order = stringList(board, 'order');
    const out: Action[] = [];
    order.forEach((strip, from) => {
      order.forEach((_, to) => {
        if (to !== from) out.push({ type: 'move', strip, to });
      });
    });
    return out;
  },

  isComplete(definition, board) {
    return sameList(stringList(board, 'order'), tileIds(definition));
  },

  progress(definition, board) {
    const ids = tileIds(definition);
    return stringList(board, 'order').filter((id, i) => id === ids[i]).length / Math.max(1, ids.length);
  },

  hint(definition, board) {
    const order = stringList(board, 'order');
    const ids = tileIds(definition);
    for (let i = 0; i < ids.length; i++) {
      if (order[i] !== ids[i]) {
        return { type: 'move', strip: ids[i] as string, to: i };
      }
    }
    return null;
  },
};
