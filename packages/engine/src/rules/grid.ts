import * as doc from '../doc.js';
import { IllegalActionError, InvalidDocumentError } from '../errors.js';
import type { Prng } from '../prng.js';
import type { Board, Rect } from '../types.js';
import type { DefinitionCore } from './types.js';

/** Shared checks for uniform-grid modes (src/Game/Rules/GridRules.php). */

export function cellSize(definition: DefinitionCore): [number, number] {
  const w = Math.floor(definition.crop.width / Math.max(1, definition.cols));
  const h = Math.floor(definition.crop.height / Math.max(1, definition.rows));
  if (w < 1 || h < 1 || w * definition.cols !== definition.crop.width || h * definition.rows !== definition.crop.height) {
    throw new InvalidDocumentError('board.crop must divide into equal cells.');
  }
  return [w, h];
}

export function cellRect(definition: DefinitionCore, index: number): Rect {
  const [w, h] = cellSize(definition);
  const row = Math.floor(index / definition.cols);
  const col = index % definition.cols;
  return { x: definition.crop.x + col * w, y: definition.crop.y + row * h, width: w, height: h };
}

export function validateGrid(definition: DefinitionCore, minRows: number, minCols: number, maxSide: number): void {
  const { kind, rows, cols, tiles } = definition;
  if (rows < minRows || cols < minCols || rows > maxSide || cols > maxSide) {
    throw new InvalidDocumentError(`A ${kind} board must be between ${minRows}x${minCols} and ${maxSide}x${maxSide}, got ${rows}x${cols}.`);
  }
  const count = rows * cols;
  if (count < 2) {
    throw new InvalidDocumentError(`A ${kind} board needs at least two tiles.`);
  }
  if (tiles.length !== count) {
    throw new InvalidDocumentError(`A ${rows}x${cols} ${kind} board needs exactly ${count} tiles, got ${tiles.length}.`);
  }
  tiles.forEach((tile, index) => {
    if (tile.home !== index) {
      throw new InvalidDocumentError(`Tile ${tile.id} must have home ${index} (tiles are listed in home order).`);
    }
    const cell = cellRect(definition, index);
    if (tile.rect.x !== cell.x || tile.rect.y !== cell.y || tile.rect.width !== cell.width || tile.rect.height !== cell.height) {
      throw new InvalidDocumentError(`Tile ${tile.id} does not show cell ${index} of board.crop.`);
    }
    const extra = Object.keys(tile.attributes);
    if (extra.length > 0) {
      throw new InvalidDocumentError(`Tile ${tile.id} has unknown field(s): ${extra.join(', ')}.`);
    }
  });
}

export function tileIds(definition: DefinitionCore): string[] {
  return definition.tiles.map((tile) => tile.id);
}

export function permutation(value: unknown, ids: readonly string[], path: string): string[] {
  const items = doc.list(value, path, 4096);
  if (items.length !== ids.length) {
    throw new InvalidDocumentError(`${path} must list ${ids.length} tiles, got ${items.length}.`);
  }
  const known = new Set(ids);
  const seen = new Set<string>();
  return items.map((item, i) => {
    const id = doc.id(item, `${path}[${i}]`);
    if (!known.has(id)) {
      throw new InvalidDocumentError(`${path}[${i}] is an unknown tile "${id}".`);
    }
    if (seen.has(id)) {
      throw new InvalidDocumentError(`${path}[${i}] repeats tile "${id}".`);
    }
    seen.add(id);
    return id;
  });
}

export function shuffledPermutation(ids: readonly string[], rng: Prng): string[] {
  const shuffled = rng.shuffle(ids);
  if (shuffled.length > 1 && shuffled.every((id, i) => id === ids[i])) {
    const first = shuffled[0] as string;
    shuffled[0] = shuffled[1] as string;
    shuffled[1] = first;
  }
  return shuffled;
}

/** Validates an action's type and field names; returns the object. */
export function actionOf(kind: string, types: readonly string[], action: unknown, fields: readonly string[]): doc.JsonObject {
  const object = doc.object(action, 'action');
  const type = doc.string(object, 'type', 'action', 32);
  if (!types.includes(type)) {
    throw new IllegalActionError(`The ${kind} mode does not support "${type}" actions.`);
  }
  doc.keys(object, ['type', ...fields], 'action');
  return object;
}

export function stringList(board: Board, key: string): string[] {
  const items = doc.list((board as unknown as doc.JsonObject)[key], `board.${key}`, 4096);
  return items.map((item) => doc.stringValue(item, `board.${key}[]`, 32));
}

export function sameList<T>(a: readonly T[], b: readonly T[]): boolean {
  return a.length === b.length && a.every((value, i) => value === b[i]);
}
