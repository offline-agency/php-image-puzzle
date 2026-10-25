import * as doc from '../doc.js';
import { IllegalActionError, InvalidDocumentError } from '../errors.js';
import { Prng } from '../prng.js';
import type { Action, Board, JigsawPiece, Rect, Tile } from '../types.js';
import { parseRect } from '../rect.js';
import type { DefinitionCore, ModeRules } from './types.js';

const MAX_SNAP = 256;
const PATH_PATTERN = /^M[-0-9. LZ]+$/;
const IMAGE_PATTERN = /^[A-Za-z0-9_][A-Za-z0-9._-]*(\/[A-Za-z0-9_][A-Za-z0-9._-]*)*$/;
const LIMIT = 1 << 20;

export function renderedRect(tile: Tile): Rect {
  return parseRect(tile.attributes['rendered'], `tile ${tile.id} rendered`);
}
export function snapDistance(definition: DefinitionCore): number {
  return doc.int(definition.rules, 'snap', 'definition.rules', 1, MAX_SNAP);
}
export function playArea(definition: DefinitionCore): Rect {
  return parseRect(definition.rules['area'], 'definition.rules.area');
}
export function trayRect(definition: DefinitionCore): Rect {
  return parseRect(definition.rules['tray'], 'definition.rules.tray');
}

function contains(outer: Rect, inner: Rect): boolean {
  return (
    inner.x >= outer.x &&
    inner.y >= outer.y &&
    inner.x + inner.width <= outer.x + outer.width &&
    inner.y + inner.height <= outer.y + outer.height
  );
}

function pieces(board: Board): JigsawPiece[] {
  const items = doc.list((board as unknown as doc.JsonObject)['pieces'], 'board.pieces', 4096);
  return items.map((value, i) => {
    const piece = doc.object(value, `board.pieces[${i}]`);
    return {
      x: doc.int(piece, 'x', `board.pieces[${i}]`),
      y: doc.int(piece, 'y', `board.pieces[${i}]`),
      placed: doc.bool(piece, 'placed', `board.pieces[${i}]`),
    };
  });
}

/**
 * Jigsaw on a world plane in source pixels (src/Game/Rules/JigsawRules.php).
 * A place within `snap` pixels of the solved box on both axes locks the piece;
 * snapping is checked before the area so edge pieces dropped slightly off the
 * board still lock.
 */
export const jigsawRules: ModeRules = {
  kind: 'jigsaw',
  actionTypes: ['place'],
  capabilities: { undo: true, redo: true, reset: true, shuffle: true, hint: true },

  validateDefinition(definition) {
    doc.keys(definition.rules, ['topology', 'snap', 'area', 'tray'], 'definition.rules');
    const topology = doc.string(definition.rules, 'topology', 'definition.rules', 16);
    if (!['rect', 'hex', 'triangle'].includes(topology)) {
      throw new InvalidDocumentError(`Unknown jigsaw topology "${topology}".`);
    }
    const snap = snapDistance(definition);
    const area = playArea(definition);
    const tray = trayRect(definition);
    if (!contains(area, tray) || !contains(area, definition.crop)) {
      throw new InvalidDocumentError('definition.rules.area must contain the board and the tray.');
    }
    if (tray.x <= definition.crop.x + definition.crop.width + snap - 1) {
      throw new InvalidDocumentError('definition.rules.tray must start more than rules.snap pixels right of the board.');
    }
    if (definition.tiles.length === 0) {
      throw new InvalidDocumentError('A jigsaw needs at least one piece.');
    }
    definition.tiles.forEach((tile, index) => {
      if (tile.home !== index) {
        throw new InvalidDocumentError(`Tile ${tile.id} must have home ${index}.`);
      }
      doc.keys(tile.attributes, ['rendered', 'image', 'path'], `definition.tiles[${index}]`);
      const rendered = renderedRect(tile);
      if (!contains(definition.crop, rendered) || !contains(rendered, tile.rect)) {
        throw new InvalidDocumentError(`Tile ${tile.id}: rendered box must contain its cell and lie on the board.`);
      }
      if (rendered.width > tray.width || rendered.height > tray.height) {
        throw new InvalidDocumentError(`Tile ${tile.id} does not fit in the tray.`);
      }
      if (!doc.has(tile.attributes, 'image')) {
        throw new InvalidDocumentError(`Tile ${tile.id} has no "image" attribute.`);
      }
      const image = doc.stringValue(tile.attributes['image'], `definition.tiles[${index}].image`, 255);
      if (!IMAGE_PATTERN.test(image)) {
        throw new InvalidDocumentError(`definition.tiles[${index}].image must be a safe relative path.`);
      }
      if (doc.has(tile.attributes, 'path')) {
        const path = doc.stringValue(tile.attributes['path'], `definition.tiles[${index}].path`, LIMIT);
        if (!PATH_PATTERN.test(path)) {
          throw new InvalidDocumentError(`definition.tiles[${index}].path must be M/L/Z path data.`);
        }
      }
    });
  },

  solvedBoard(definition) {
    return {
      pieces: definition.tiles.map((tile) => {
        const rendered = renderedRect(tile);
        return { x: rendered.x, y: rendered.y, placed: true };
      }),
    };
  },

  scramble(definition, seed) {
    const rng = new Prng(seed, 'image-puzzle/scramble/jigsaw');
    const tray = trayRect(definition);
    return {
      pieces: definition.tiles.map((tile) => {
        const rendered = renderedRect(tile);
        const x = tray.x + rng.int(tray.width - rendered.width + 1);
        const y = tray.y + rng.int(tray.height - rendered.height + 1);
        return { x, y, placed: false };
      }),
    };
  },

  validateBoard(definition, value) {
    const board = doc.object(value, 'board');
    doc.keys(board, ['pieces'], 'board');
    const items = doc.list(board['pieces'], 'board.pieces', 4096);
    if (items.length !== definition.tiles.length) {
      throw new InvalidDocumentError('board.pieces must have one entry per tile.');
    }
    const snap = snapDistance(definition);
    const area = playArea(definition);
    return {
      pieces: items.map((item, i) => {
        const piece = doc.object(item, `board.pieces[${i}]`);
        doc.keys(piece, ['x', 'y', 'placed'], `board.pieces[${i}]`);
        const x = doc.int(piece, 'x', `board.pieces[${i}]`, -LIMIT, LIMIT);
        const y = doc.int(piece, 'y', `board.pieces[${i}]`, -LIMIT, LIMIT);
        const placed = doc.bool(piece, 'placed', `board.pieces[${i}]`);
        const rendered = renderedRect(definition.tiles[i] as Tile);
        if (placed && (x !== rendered.x || y !== rendered.y)) {
          throw new InvalidDocumentError(`board.pieces[${i}] is marked placed away from its solved position.`);
        }
        if (!placed && Math.abs(x - rendered.x) <= snap && Math.abs(y - rendered.y) <= snap) {
          throw new InvalidDocumentError(`board.pieces[${i}] lies inside its snap zone but is not placed.`);
        }
        if (!contains(area, { x, y, width: rendered.width, height: rendered.height })) {
          throw new InvalidDocumentError(`board.pieces[${i}] lies outside rules.area.`);
        }
        return { x, y, placed };
      }),
    };
  },

  apply(definition, board, value) {
    const action = doc.object(value, 'action');
    const type = doc.string(action, 'type', 'action', 32);
    if (type !== 'place') {
      throw new IllegalActionError(`The jigsaw mode does not support "${type}" actions.`);
    }
    doc.keys(action, ['type', 'piece', 'x', 'y'], 'action');
    const id = doc.id(action['piece'], 'action.piece');
    const x = doc.int(action, 'x', 'action', -LIMIT, LIMIT);
    const y = doc.int(action, 'y', 'action', -LIMIT, LIMIT);
    const index = definition.tiles.findIndex((tile) => tile.id === id);
    if (index < 0) {
      throw new IllegalActionError(`Piece "${id}" is not on the board.`);
    }
    const list = pieces(board);
    if (list[index]?.placed) {
      throw new IllegalActionError(`Piece "${id}" is already placed.`);
    }
    const rendered = renderedRect(definition.tiles[index] as Tile);
    const snap = snapDistance(definition);
    if (Math.abs(x - rendered.x) <= snap && Math.abs(y - rendered.y) <= snap) {
      list[index] = { x: rendered.x, y: rendered.y, placed: true };
      return { pieces: list };
    }
    if (!contains(playArea(definition), { x, y, width: rendered.width, height: rendered.height })) {
      throw new IllegalActionError(`Piece "${id}" cannot leave the play area.`);
    }
    list[index] = { x, y, placed: false };
    return { pieces: list };
  },

  legalActions(definition, board): Action[] {
    const out: Action[] = [];
    pieces(board).forEach((piece, i) => {
      if (!piece.placed) {
        const tile = definition.tiles[i] as Tile;
        const rendered = renderedRect(tile);
        out.push({ type: 'place', piece: tile.id, x: rendered.x, y: rendered.y });
      }
    });
    return out;
  },

  isComplete(_definition, board) {
    return pieces(board).every((piece) => piece.placed);
  },

  progress(_definition, board) {
    const list = pieces(board);
    return list.filter((piece) => piece.placed).length / Math.max(1, list.length);
  },

  hint(definition, board) {
    return this.legalActions(definition, board)[0] ?? null;
  },
};
