/** Game kinds known to the format. Not every kind ships rules in every version. */
export type GameKind = 'sliding' | 'swap' | 'rotation' | 'strips' | 'jigsaw' | 'memory' | 'tangram' | 'nonogram';

export const GAME_KINDS: readonly GameKind[] = ['sliding', 'swap', 'rotation', 'strips', 'jigsaw', 'memory', 'tangram', 'nonogram'];

export interface Rect {
  readonly x: number;
  readonly y: number;
  readonly width: number;
  readonly height: number;
}

export interface Tile {
  readonly id: string;
  readonly home: number;
  readonly rect: Rect;
  /** Mode-specific data (jigsaw: rendered, image, path). */
  readonly attributes: { readonly [key: string]: unknown };
}

export interface Capabilities {
  readonly undo: boolean;
  readonly redo: boolean;
  readonly reset: boolean;
  readonly shuffle: boolean;
  readonly hint: boolean;
}

export interface Assistance {
  readonly preview: boolean;
  readonly numbers: boolean;
  readonly highlightCorrect: boolean;
}

/** A smaller set of jigsaw tile images (definition.media.resolutions). */
export interface PieceResolution {
  readonly name: string;
  /** Width of the whole source at this resolution: the scale is width / source.width. */
  readonly width: number;
  readonly directory: string;
}

/** Spritesheet of every jigsaw tile image (definition.media.atlas). */
export interface PieceAtlas {
  readonly image: string;
  readonly width: number;
  readonly height: number;
  /** Rendered box of each tile in atlas pixels, by tile ID in tile order. */
  readonly frames: { readonly [id: string]: Rect };
}

export interface Media {
  readonly image: string;
  readonly resolutions?: readonly PieceResolution[];
  readonly atlas?: PieceAtlas;
}

/** A validated, immutable game definition (docs/schemas/game/v1/definition.schema.json). */
export interface GameDefinition {
  readonly id: string;
  readonly kind: GameKind;
  readonly seed: number;
  readonly preset: { readonly name: string; readonly version: number };
  readonly source: { readonly width: number; readonly height: number; readonly sha256: string | null };
  readonly media: Media;
  readonly rows: number;
  readonly cols: number;
  readonly crop: Rect;
  readonly tiles: readonly Tile[];
  readonly rules: { readonly [key: string]: unknown };
  readonly actions: readonly string[];
  readonly capabilities: Capabilities;
  readonly assistance: Assistance;
  readonly initial: Board;
}

export interface SlidingBoard {
  readonly cells: readonly (string | null)[];
}
export interface SwapBoard {
  readonly cells: readonly string[];
}
export interface RotationBoard {
  readonly orientations: readonly number[];
}
export interface StripsBoard {
  readonly order: readonly string[];
}
export interface JigsawPiece {
  readonly x: number;
  readonly y: number;
  readonly placed: boolean;
}
export interface JigsawBoard {
  readonly pieces: readonly JigsawPiece[];
}

export type Board = SlidingBoard | SwapBoard | RotationBoard | StripsBoard | JigsawBoard;

export type Action =
  | { readonly type: 'slide'; readonly tile: string }
  | { readonly type: 'swap'; readonly a: string; readonly b: string }
  | { readonly type: 'rotate'; readonly tile: string; readonly direction: 'cw' | 'ccw' }
  | { readonly type: 'move'; readonly strip: string; readonly to: number }
  | { readonly type: 'place'; readonly piece: string; readonly x: number; readonly y: number };

/** Exported state document (docs/schemas/game/v1/state.schema.json). */
export interface GameStateDocument {
  readonly $schema: string;
  readonly format: 'image-puzzle/game-state';
  readonly version: 1;
  readonly definitionId: string;
  readonly kind: GameKind;
  readonly board: Board;
  readonly moves: number;
  readonly elapsedMs: number;
  readonly hintsUsed: number;
  readonly completed: boolean;
  readonly history: { readonly past: readonly Board[]; readonly future: readonly Board[] };
}
