import * as doc from '../doc.js';
import { IllegalActionError, InvalidDocumentError } from '../errors.js';
import { Prng } from '../prng.js';
import type { Action, Board } from '../types.js';
import { actionOf, cellSize, validateGrid } from './grid.js';
import type { DefinitionCore, ModeRules } from './types.js';

const TYPES = ['rotate'] as const;
const ANGLES = [0, 90, 180, 270];

/**
 * Orientation convention: clockwise degrees applied to the tile image when
 * displayed; 0 is upright. "cw" moves to the next larger allowed angle.
 */
export function allowedOrientations(definition: DefinitionCore): number[] {
  const items = doc.list(definition.rules['orientations'], 'definition.rules.orientations', 4);
  const angles = items.map((value, i) => doc.intValue(value, `definition.rules.orientations[${i}]`));
  const sorted = [...angles].sort((a, b) => a - b);
  const valid =
    angles.every((angle, i) => angle === sorted[i]) &&
    new Set(angles).size === angles.length &&
    angles.every((angle) => ANGLES.includes(angle)) &&
    angles.length >= 2 &&
    angles[0] === 0;
  if (!valid) {
    throw new InvalidDocumentError(
      'definition.rules.orientations must be an ascending subset of [0, 90, 180, 270] with 0 and at least two angles.',
    );
  }
  return angles;
}

function orientations(board: Board): number[] {
  const items = doc.list((board as unknown as doc.JsonObject)['orientations'], 'board.orientations', 4096);
  return items.map((value) => doc.intValue(value, 'board.orientations[]'));
}

export const rotationRules: ModeRules = {
  kind: 'rotation',
  actionTypes: TYPES,
  capabilities: { undo: true, redo: true, reset: true, shuffle: true, hint: true },

  validateDefinition(definition) {
    validateGrid(definition, 1, 1, 16);
    doc.keys(definition.rules, ['orientations'], 'definition.rules');
    const allowed = allowedOrientations(definition);
    const [w, h] = cellSize(definition);
    if (w !== h && (allowed.includes(90) || allowed.includes(270))) {
      throw new InvalidDocumentError(`Quarter turns need square cells; cells are ${w}x${h}.`);
    }
  },

  solvedBoard(definition) {
    return { orientations: definition.tiles.map(() => 0) };
  },

  scramble(definition, seed) {
    const rng = new Prng(seed, 'image-puzzle/scramble/rotation');
    const allowed = allowedOrientations(definition);
    const out: number[] = definition.tiles.map(() => allowed[rng.int(allowed.length)] as number);
    if (!out.some((angle) => angle !== 0)) {
      out[0] = allowed[1] as number;
    }
    return { orientations: out };
  },

  validateBoard(definition, value) {
    const board = doc.object(value, 'board');
    doc.keys(board, ['orientations'], 'board');
    const items = doc.list(board['orientations'], 'board.orientations', 4096);
    if (items.length !== definition.tiles.length) {
      throw new InvalidDocumentError('board.orientations must have one entry per tile.');
    }
    const allowed = allowedOrientations(definition);
    return {
      orientations: items.map((item, i) => {
        const angle = doc.intValue(item, `board.orientations[${i}]`);
        if (!allowed.includes(angle)) {
          throw new InvalidDocumentError(`board.orientations[${i}] = ${angle} is not an allowed orientation.`);
        }
        return angle;
      }),
    };
  },

  apply(definition, board, value) {
    const action = actionOf('rotation', TYPES, value, ['tile', 'direction']);
    const tile = doc.id(action['tile'], 'action.tile');
    const direction = doc.string(action, 'direction', 'action', 8);
    if (direction !== 'cw' && direction !== 'ccw') {
      throw new InvalidDocumentError('action.direction must be "cw" or "ccw".');
    }
    const index = definition.tiles.findIndex((candidate) => candidate.id === tile);
    if (index < 0) {
      throw new IllegalActionError(`Tile "${tile}" is not on the board.`);
    }
    const out = orientations(board);
    const allowed = allowedOrientations(definition);
    const k = allowed.indexOf(out[index] ?? -1);
    if (k < 0) {
      throw new InvalidDocumentError(`board.orientations[${index}] is not an allowed orientation.`);
    }
    const n = allowed.length;
    out[index] = allowed[(k + (direction === 'cw' ? 1 : n - 1)) % n] as number;
    return { orientations: out };
  },

  legalActions(definition): Action[] {
    const twoWay = allowedOrientations(definition).length > 2;
    const out: Action[] = [];
    for (const tile of definition.tiles) {
      out.push({ type: 'rotate', tile: tile.id, direction: 'cw' });
      if (twoWay) out.push({ type: 'rotate', tile: tile.id, direction: 'ccw' });
    }
    return out;
  },

  isComplete(_definition, board) {
    return orientations(board).every((angle) => angle === 0);
  },

  progress(_definition, board) {
    const list = orientations(board);
    return list.filter((angle) => angle === 0).length / Math.max(1, list.length);
  },

  hint(definition, board) {
    const allowed = allowedOrientations(definition);
    const n = allowed.length;
    const list = orientations(board);
    for (let i = 0; i < list.length; i++) {
      const angle = list[i] as number;
      if (angle !== 0) {
        const k = Math.max(0, allowed.indexOf(angle));
        return { type: 'rotate', tile: definition.tiles[i]?.id ?? '', direction: k < n - k ? 'ccw' : 'cw' };
      }
    }
    return null;
  },
};
