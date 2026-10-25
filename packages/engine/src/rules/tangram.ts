import * as doc from '../doc.js';
import { IllegalActionError, InvalidDocumentError } from '../errors.js';
import { Prng } from '../prng.js';
import { parseRect } from '../rect.js';
import type { Action, Board, Rect, TangramPose } from '../types.js';
import type { DefinitionCore, ModeRules } from './types.js';

/**
 * Tangram (src/Game/Tangram/Tangram.php, src/Game/Rules/TangramRules.php).
 * Square of side 4 units, x right, y down. A pose puts a piece's centroid at
 * integer pixels, mirrors it across the vertical axis through the centroid
 * when flipped, then turns it clockwise by rotation x 45 degrees. Every
 * floating-point operation happens in the same order as in PHP, so both
 * compute identical doubles.
 */

export const TANGRAM_EPSILON = 1e-6;
export const TANGRAM_MIN_UNIT = 12;
const MAX_SNAP = 256;
const TRAY_COLS = 4;
const TRAY_ROWS = 2;
const TRAY_CELL = 3;
const LIMIT = 1 << 20;

export type TangramShape = 'large' | 'medium' | 'small' | 'square' | 'parallelogram';
type Point = readonly [number, number];

export const TANGRAM_PIECES: Readonly<Record<string, { readonly shape: TangramShape; readonly vertices: readonly Point[] }>> = {
  'large-a': {
    shape: 'large',
    vertices: [
      [0, 0],
      [4, 0],
      [2, 2],
    ],
  },
  'large-b': {
    shape: 'large',
    vertices: [
      [0, 0],
      [2, 2],
      [0, 4],
    ],
  },
  medium: {
    shape: 'medium',
    vertices: [
      [4, 2],
      [4, 4],
      [2, 4],
    ],
  },
  'small-a': {
    shape: 'small',
    vertices: [
      [4, 0],
      [4, 2],
      [3, 1],
    ],
  },
  'small-b': {
    shape: 'small',
    vertices: [
      [2, 2],
      [3, 3],
      [1, 3],
    ],
  },
  square: {
    shape: 'square',
    vertices: [
      [2, 2],
      [3, 1],
      [4, 2],
      [3, 3],
    ],
  },
  parallelogram: {
    shape: 'parallelogram',
    vertices: [
      [0, 4],
      [1, 3],
      [3, 3],
      [2, 4],
    ],
  },
};

/** Validated targets: picture targets bind each slot to its own piece; silhouettes accept any piece of the slot's shape. */
export const TANGRAM_TARGETS: Readonly<Record<string, { readonly picture: boolean; readonly moves: Readonly<Record<string, Point>> }>> = {
  square: { picture: true, moves: {} },
  arrow: { picture: false, moves: { 'large-b': [4, 0] } },
  chevron: { picture: false, moves: { 'large-a': [0, 4] } },
};

const TURNS: readonly Point[] = [
  [1.0, 0.0],
  [Math.SQRT1_2, Math.SQRT1_2],
  [0.0, 1.0],
  [-Math.SQRT1_2, Math.SQRT1_2],
  [-1.0, 0.0],
  [-Math.SQRT1_2, -Math.SQRT1_2],
  [0.0, -1.0],
  [Math.SQRT1_2, -Math.SQRT1_2],
];

export interface TangramSlot extends TangramPose {
  readonly piece: string;
  readonly shape: TangramShape;
  readonly bound: boolean;
}

export const tangramIds = (): string[] => Object.keys(TANGRAM_PIECES);

function piece(id: string): { readonly shape: TangramShape; readonly vertices: readonly Point[] } {
  const found = TANGRAM_PIECES[id];
  if (found === undefined) throw new InvalidDocumentError(`Unknown tangram piece "${id}".`);
  return found;
}

function target(name: string): { readonly picture: boolean; readonly moves: Readonly<Record<string, Point>> } {
  const found = Object.hasOwn(TANGRAM_TARGETS, name) ? TANGRAM_TARGETS[name] : undefined;
  if (found === undefined) {
    throw new InvalidDocumentError(
      `Unknown tangram target "${name}"; supported targets: ${Object.keys(TANGRAM_TARGETS).join(', ')}. Other silhouettes are not recognised.`,
    );
  }
  return found;
}

/** Solved vertices of a piece in world pixels. */
export function tangramHome(id: string, originX: number, originY: number, unit: number): Point[] {
  return piece(id).vertices.map(([vx, vy]) => [originX + vx * unit, originY + vy * unit] as const);
}

function homeCentroid(id: string, originX: number, originY: number, unit: number): Point {
  const { vertices } = piece(id);
  const n = vertices.length;
  const sx = vertices.reduce((sum, [vx]) => sum + vx, 0) * unit;
  const sy = vertices.reduce((sum, [, vy]) => sum + vy, 0) * unit;
  return [originX + Math.trunc(sx / n), originY + Math.trunc(sy / n)];
}

/** World vertices of a piece in a pose. */
export function tangramPolygon(id: string, unit: number, x: number, y: number, rotation: number, flipped: boolean): Point[] {
  const [cos, sin] = TURNS[rotation] as Point;
  const { vertices } = piece(id);
  const n = vertices.length;
  const cx = (vertices.reduce((sum, [vx]) => sum + vx, 0) * unit) / n;
  const cy = (vertices.reduce((sum, [, vy]) => sum + vy, 0) * unit) / n;
  return vertices.map(([vx, vy]) => {
    let lx = vx * unit - cx;
    const ly = vy * unit - cy;
    if (flipped) lx = -lx;
    return [x + (lx * cos - ly * sin), y + (lx * sin + ly * cos)] as const;
  });
}

/** Slots of a target in world pixels. */
export function tangramSlots(name: string, originX: number, originY: number, unit: number): TangramSlot[] {
  const definition = target(name);
  return tangramIds().map((id) => {
    const [dx, dy] = definition.moves[id] ?? [0, 0];
    const [cx, cy] = homeCentroid(id, originX, originY, unit);
    return {
      piece: id,
      shape: piece(id).shape,
      bound: definition.picture,
      x: cx + dx * unit,
      y: cy + dy * unit,
      rotation: 0,
      flipped: false,
    };
  });
}

/** Bounding box of a target in units: minX, minY, maxX, maxY. */
export function tangramExtent(name: string): [number, number, number, number] {
  let [minX, minY, maxX, maxY] = [0, 0, 4, 4];
  for (const [id, [dx, dy]] of Object.entries(target(name).moves)) {
    for (const [vx, vy] of piece(id).vertices) {
      minX = Math.min(minX, vx + dx);
      minY = Math.min(minY, vy + dy);
      maxX = Math.max(maxX, vx + dx);
      maxY = Math.max(maxY, vy + dy);
    }
  }
  return [minX, minY, maxX, maxY];
}

function directed(a: readonly Point[], b: readonly Point[]): number {
  let worst = 0.0;
  for (const [ax, ay] of a) {
    let best = Infinity;
    for (const [bx, by] of b) {
      best = Math.min(best, Math.sqrt((ax - bx) * (ax - bx) + (ay - by) * (ay - by)));
    }
    worst = Math.max(worst, best);
  }
  return worst;
}

/** Largest distance from any vertex of one polygon to the nearest vertex of the other. */
export function tangramDistance(a: readonly Point[], b: readonly Point[]): number {
  if (a.length !== b.length) return Infinity;
  return Math.max(directed(a, b), directed(b, a));
}

/** Pose that puts piece `id` exactly on `slot` (preferring `prefer`'s orientation), or null. */
export function tangramFit(id: string, slot: TangramSlot, unit: number, prefer: TangramPose | null = null): TangramPose | null {
  if (piece(id).shape !== slot.shape || (slot.bound && slot.piece !== id)) return null;
  if (slot.bound) return { x: slot.x, y: slot.y, rotation: slot.rotation, flipped: slot.flipped };
  const goal = tangramPolygon(slot.piece, unit, slot.x, slot.y, slot.rotation, slot.flipped);
  if (
    prefer !== null &&
    tangramDistance(tangramPolygon(id, unit, slot.x, slot.y, prefer.rotation, prefer.flipped), goal) <= TANGRAM_EPSILON
  ) {
    return { x: slot.x, y: slot.y, rotation: prefer.rotation, flipped: prefer.flipped };
  }
  for (const flipped of [false, true]) {
    for (let rotation = 0; rotation < 8; rotation++) {
      if (tangramDistance(tangramPolygon(id, unit, slot.x, slot.y, rotation, flipped), goal) <= TANGRAM_EPSILON) {
        return { x: slot.x, y: slot.y, rotation, flipped };
      }
    }
  }
  return null;
}

/** SVG path data of a polygon with integer vertices. */
export function tangramPath(vertices: readonly Point[]): string {
  return vertices.map(([x, y], i) => `${i === 0 ? 'M ' : 'L '}${x} ${y}`).join(' ') + ' Z';
}

/** Play area and tray of a target, in world pixels. */
export function tangramLayout(name: string, crop: Rect, unit: number): { area: Rect; tray: Rect } {
  const [minX, minY, maxX, maxY] = tangramExtent(name);
  const centre = crop.x + Math.trunc(((minX + maxX) * unit) / 2);
  const trayWidth = TRAY_COLS * TRAY_CELL * unit;
  const tray = {
    x: centre - Math.trunc(trayWidth / 2),
    y: crop.y + (maxY + 1) * unit,
    width: trayWidth,
    height: TRAY_ROWS * TRAY_CELL * unit,
  };
  const left = Math.min(crop.x + minX * unit, tray.x) - unit;
  const top = crop.y + minY * unit - unit;
  const right = Math.max(crop.x + maxX * unit, tray.x + tray.width) + unit;
  const bottom = tray.y + tray.height + unit;
  return { area: { x: left, y: top, width: right - left, height: bottom - top }, tray };
}

export const tangramUnit = (definition: DefinitionCore): number =>
  doc.int(definition.rules, 'unit', 'definition.rules', TANGRAM_MIN_UNIT, 1 << 16);
export const tangramTarget = (definition: DefinitionCore): string => doc.string(definition.rules, 'target', 'definition.rules', 32);

export function tangramSlotsOf(definition: DefinitionCore): TangramSlot[] {
  return tangramSlots(tangramTarget(definition), definition.crop.x, definition.crop.y, tangramUnit(definition));
}

function poseOf(value: unknown, path: string): TangramPose {
  const pose = doc.object(value, path);
  doc.keys(pose, ['x', 'y', 'rotation', 'flipped'], path);
  return {
    x: doc.int(pose, 'x', path, -LIMIT, LIMIT),
    y: doc.int(pose, 'y', path, -LIMIT, LIMIT),
    rotation: doc.int(pose, 'rotation', path, 0, 7),
    flipped: doc.bool(pose, 'flipped', path),
  };
}

function pieces(board: Board): TangramPose[] {
  return doc.list((board as unknown as doc.JsonObject)['pieces'], 'board.pieces', 7).map((value, i) => poseOf(value, `board.pieces[${i}]`));
}

const samePose = (a: TangramPose | undefined, b: TangramPose): boolean =>
  a !== undefined && a.x === b.x && a.y === b.y && a.rotation === b.rotation && a.flipped === b.flipped;

/** Slot index of each piece that lies exactly on a slot it fits, greedily in tile order. */
export function tangramAssignment(definition: DefinitionCore, list: readonly TangramPose[]): Map<number, number> {
  const unit = tangramUnit(definition);
  const slots = tangramSlotsOf(definition);
  const taken = new Set<number>();
  const assignment = new Map<number, number>();
  tangramIds().forEach((id, index) => {
    const pose = list[index] as TangramPose;
    const polygon = tangramPolygon(id, unit, pose.x, pose.y, pose.rotation, pose.flipped);
    for (const [s, slot] of slots.entries()) {
      if (taken.has(s) || piece(id).shape !== slot.shape) continue;
      const matches = slot.bound
        ? slot.piece === id && samePose(pose, slot)
        : tangramDistance(polygon, tangramPolygon(slot.piece, unit, slot.x, slot.y, slot.rotation, slot.flipped)) <= TANGRAM_EPSILON;
      if (matches) {
        assignment.set(index, s);
        taken.add(s);
        break;
      }
    }
  });
  return assignment;
}

function inside(definition: DefinitionCore, id: string, pose: TangramPose): boolean {
  const area = parseRect(definition.rules['area'], 'definition.rules.area');
  return tangramPolygon(id, tangramUnit(definition), pose.x, pose.y, pose.rotation, pose.flipped).every(
    ([x, y]) =>
      !(
        x < area.x - TANGRAM_EPSILON ||
        y < area.y - TANGRAM_EPSILON ||
        x > area.x + area.width + TANGRAM_EPSILON ||
        y > area.y + area.height + TANGRAM_EPSILON
      ),
  );
}

function snapped(definition: DefinitionCore, list: readonly TangramPose[], index: number, pose: TangramPose): TangramPose {
  const id = tangramIds()[index] as string;
  const unit = tangramUnit(definition);
  const snap = doc.int(definition.rules, 'snap', 'definition.rules', 1, MAX_SNAP);
  const taken = tangramAssignment(definition, list);
  taken.delete(index);
  const occupied = new Set(taken.values());
  const polygon = tangramPolygon(id, unit, pose.x, pose.y, pose.rotation, pose.flipped);
  let best: TangramPose | null = null;
  let bestDistance = Infinity;
  for (const [s, slot] of tangramSlotsOf(definition).entries()) {
    if (occupied.has(s)) continue;
    const fit = tangramFit(id, slot, unit, pose);
    if (fit === null) continue;
    const distance = tangramDistance(polygon, tangramPolygon(id, unit, fit.x, fit.y, fit.rotation, fit.flipped));
    if (distance <= snap && distance < bestDistance) {
      best = fit;
      bestDistance = distance;
    }
  }
  return best ?? pose;
}

export const tangramRules: ModeRules = {
  kind: 'tangram',
  actionTypes: ['pose'],
  capabilities: { undo: true, redo: true, reset: true, shuffle: true, hint: true },

  validateDefinition(definition) {
    const { rules, crop } = definition;
    doc.keys(rules, ['target', 'unit', 'snap', 'area', 'tray'], 'definition.rules');
    const name = doc.string(rules, 'target', 'definition.rules', 32);
    if (!Object.hasOwn(TANGRAM_TARGETS, name)) {
      throw new InvalidDocumentError(
        `definition.rules.target "${name}" is not a supported tangram target (${Object.keys(TANGRAM_TARGETS).join(', ')}).`,
      );
    }
    const unit = tangramUnit(definition);
    if (unit % 6 !== 0) throw new InvalidDocumentError('definition.rules.unit must be a multiple of 6.');
    const snap = doc.int(rules, 'snap', 'definition.rules', 1, MAX_SNAP);
    if (snap >= unit) throw new InvalidDocumentError('definition.rules.snap must be smaller than rules.unit.');
    if (definition.rows !== 4 || definition.cols !== 4 || crop.width !== 4 * unit || crop.height !== 4 * unit) {
      throw new InvalidDocumentError('A tangram board is 4x4 units: board.crop must be a square of side 4 x rules.unit.');
    }
    const layout = tangramLayout(name, crop, unit);
    for (const key of ['area', 'tray'] as const) {
      const rect = parseRect(rules[key], `definition.rules.${key}`);
      const want = layout[key];
      if (rect.x !== want.x || rect.y !== want.y || rect.width !== want.width || rect.height !== want.height) {
        throw new InvalidDocumentError(`definition.rules.${key} must be ${JSON.stringify(want)} for this target and unit.`);
      }
    }
    const ids = tangramIds();
    if (definition.tiles.length !== ids.length) {
      throw new InvalidDocumentError(`A tangram has exactly ${ids.length} pieces.`);
    }
    definition.tiles.forEach((tile, index) => {
      const id = ids[index] as string;
      const home = tangramHome(id, crop.x, crop.y, unit);
      const xs = home.map(([x]) => x);
      const ys = home.map(([, y]) => y);
      const box = [Math.min(...xs), Math.min(...ys), Math.max(...xs) - Math.min(...xs), Math.max(...ys) - Math.min(...ys)];
      const rect = [tile.rect.x, tile.rect.y, tile.rect.width, tile.rect.height];
      if (tile.id !== id || tile.home !== index || rect.some((v, i) => v !== box[i])) {
        throw new InvalidDocumentError(`Piece ${index} must be "${id}" with home ${index} and its solved bounding box.`);
      }
      doc.keys(tile.attributes, ['shape', 'path'], `definition.tiles[${index}]`);
      const shape = piece(id).shape;
      if (tile.attributes['shape'] !== shape || tile.attributes['path'] !== tangramPath(home)) {
        throw new InvalidDocumentError(`definition.tiles[${index}] must have shape "${shape}" and the solved outline as path.`);
      }
    });
  },

  solvedBoard(definition) {
    const unit = tangramUnit(definition);
    return {
      pieces: tangramSlotsOf(definition).map((slot) => {
        const fit = tangramFit(slot.piece, slot, unit);
        if (fit === null) throw new InvalidDocumentError('The tangram target does not fit its pieces.');
        return fit;
      }),
    };
  },

  scramble(definition, seed) {
    const rng = new Prng(seed, 'image-puzzle/scramble/tangram');
    const tray = parseRect(definition.rules['tray'], 'definition.rules.tray');
    const unit = tangramUnit(definition);
    const cells = rng.shuffle(Array.from({ length: TRAY_COLS * TRAY_ROWS }, (_, i) => i));
    const half = Math.trunc((TRAY_CELL * unit) / 2);
    return {
      pieces: definition.tiles.map((_tile, i) => {
        const cell = cells[i] as number;
        return {
          x: tray.x + (cell % TRAY_COLS) * TRAY_CELL * unit + half,
          y: tray.y + Math.trunc(cell / TRAY_COLS) * TRAY_CELL * unit + half,
          rotation: rng.int(8),
          flipped: rng.int(2) === 1,
        };
      }),
    };
  },

  validateBoard(definition, value) {
    const board = doc.object(value, 'board');
    doc.keys(board, ['pieces'], 'board');
    const items = doc.list(board['pieces'], 'board.pieces', definition.tiles.length);
    if (items.length !== definition.tiles.length) {
      throw new InvalidDocumentError('board.pieces must have one entry per piece.');
    }
    return {
      pieces: items.map((item, i) => {
        const pose = poseOf(item, `board.pieces[${i}]`);
        if (!inside(definition, definition.tiles[i]?.id ?? '', pose)) {
          throw new InvalidDocumentError(`board.pieces[${i}] lies outside rules.area.`);
        }
        return pose;
      }),
    };
  },

  apply(definition, board, value) {
    const action = doc.object(value, 'action');
    const type = doc.string(action, 'type', 'action', 32);
    if (type !== 'pose') {
      throw new IllegalActionError(`The tangram mode does not support "${type}" actions.`);
    }
    doc.keys(action, ['type', 'piece', 'x', 'y', 'rotation', 'flipped'], 'action');
    const id = doc.id(action['piece'], 'action.piece');
    const index = tangramIds().indexOf(id);
    if (index < 0) {
      throw new IllegalActionError(`Piece "${id}" is not on the board.`);
    }
    let pose = poseOf(
      { x: action['x'] ?? null, y: action['y'] ?? null, rotation: action['rotation'] ?? null, flipped: action['flipped'] ?? null },
      'action',
    );
    const list = pieces(board);
    if (!inside(definition, id, pose)) {
      throw new IllegalActionError(`Piece "${id}" would leave the play area.`);
    }
    pose = snapped(definition, list, index, pose);
    if (samePose(list[index], pose)) {
      throw new IllegalActionError(`Piece "${id}" is already in that pose.`);
    }
    list[index] = pose;
    return { pieces: list };
  },

  legalActions(definition, board): Action[] {
    const list = pieces(board);
    const assignment = tangramAssignment(definition, list);
    const occupied = new Set(assignment.values());
    const unit = tangramUnit(definition);
    const slots = tangramSlotsOf(definition);
    const out: Action[] = [];
    tangramIds().forEach((id, index) => {
      if (assignment.has(index)) return;
      slots.forEach((slot, s) => {
        if (occupied.has(s)) return;
        const fit = tangramFit(id, slot, unit);
        if (fit !== null && !samePose(list[index], fit)) out.push({ type: 'pose', piece: id, ...fit });
      });
    });
    return out;
  },

  isComplete(definition, board) {
    return tangramAssignment(definition, pieces(board)).size === tangramIds().length;
  },

  progress(definition, board) {
    return tangramAssignment(definition, pieces(board)).size / tangramIds().length;
  },

  hint(definition, board) {
    const list = pieces(board);
    const assignment = tangramAssignment(definition, list);
    const occupied = new Set(assignment.values());
    const slots = tangramSlotsOf(definition);
    const unit = tangramUnit(definition);
    for (const [index, id] of tangramIds().entries()) {
      if (assignment.has(index)) continue;
      for (const s of [index, ...slots.keys()]) {
        if (occupied.has(s)) continue;
        const fit = tangramFit(id, slots[s] as TangramSlot, unit);
        if (fit !== null) return { type: 'pose', piece: id, ...fit };
      }
    }
    return null;
  },
};
