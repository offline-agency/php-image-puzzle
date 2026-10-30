import { InvalidDocumentError } from './errors.js';
import * as doc from './doc.js';
import type { JsonObject } from './doc.js';
import { MAX_SEED } from './prng.js';

/**
 * Build configurations (docs/schemas/game/v1/build-config.schema.json): the
 * reproducible input of the PHP DefinitionBuilder. Validation mirrors
 * src/Game/BuildConfiguration.php message for message
 * (conformance/build-config.json); the server stays authoritative.
 */

export const BUILD_CONFIG_FORMAT = 'image-puzzle/build-config';
export const BUILD_CONFIG_VERSION = 1;
export const MAX_CROP = 100000;
/** Jigsaw seeds also drive the PHP outline geometry, which takes 31 bits. */
export const MAX_JIGSAW_SEED = 2147483647;

export const BUILD_KINDS = ['sliding', 'swap', 'rotation', 'strips', 'jigsaw', 'nonogram', 'memory', 'tangram'] as const;
export type BuildKind = (typeof BUILD_KINDS)[number];

export const PRESET_NAMES = ['easy', 'medium', 'hard'] as const;
export type PresetName = (typeof PRESET_NAMES)[number];

export const CUSTOM_KEYS: Readonly<Record<BuildKind, readonly string[]>> = {
  sliding: ['rows', 'cols', 'scrambleMoves', 'assistance'],
  swap: ['rows', 'cols', 'assistance'],
  rotation: ['rows', 'cols', 'orientations', 'assistance'],
  strips: ['count', 'axis', 'assistance'],
  jigsaw: ['rows', 'cols', 'topology', 'edge', 'snap'],
  nonogram: ['rows', 'cols', 'threshold', 'invert', 'requireUnique', 'assistance'],
  memory: ['rows', 'cols', 'labels', 'assistance'],
  tangram: ['target', 'snap', 'assistance'],
};

/** Board shape parameters of each preset (src/Game/Presets.php, conformance/presets.json). */
export const PRESET_SHAPES: Readonly<Record<BuildKind, Readonly<Record<PresetName, Readonly<Record<string, unknown>>>>>> = {
  sliding: {
    easy: { rows: 3, cols: 3, scrambleMoves: 60 },
    medium: { rows: 4, cols: 4, scrambleMoves: 200 },
    hard: { rows: 5, cols: 5, scrambleMoves: 500 },
  },
  swap: { easy: { rows: 3, cols: 3 }, medium: { rows: 4, cols: 4 }, hard: { rows: 6, cols: 6 } },
  rotation: {
    easy: { rows: 3, cols: 3, orientations: [0, 180] },
    medium: { rows: 4, cols: 4, orientations: [0, 90, 180, 270] },
    hard: { rows: 6, cols: 6, orientations: [0, 90, 180, 270] },
  },
  strips: {
    easy: { count: 4, axis: 'horizontal' },
    medium: { count: 8, axis: 'horizontal' },
    hard: { count: 14, axis: 'vertical' },
  },
  jigsaw: {
    easy: { rows: 3, cols: 4, edge: 'jigsaw' },
    medium: { rows: 6, cols: 8, edge: 'jigsaw' },
    hard: { rows: 10, cols: 12, edge: 'jigsaw' },
  },
  nonogram: { easy: { rows: 10, cols: 10 }, medium: { rows: 15, cols: 15 }, hard: { rows: 20, cols: 20 } },
  memory: { easy: { rows: 3, cols: 4 }, medium: { rows: 4, cols: 4 }, hard: { rows: 4, cols: 6 } },
  tangram: { easy: { target: 'square', snap: 50 }, medium: { target: 'square', snap: 30 }, hard: { target: 'square', snap: 15 } },
};

export interface CropRect {
  readonly x: number;
  readonly y: number;
  readonly width: number;
  readonly height: number;
}

export interface BuildConfig {
  readonly kind: BuildKind;
  readonly preset: PresetName | 'custom';
  readonly custom: JsonObject;
  readonly seed: number;
  readonly crop: CropRect | null;
}

export interface BuildConfigDocument {
  readonly format: typeof BUILD_CONFIG_FORMAT;
  readonly version: typeof BUILD_CONFIG_VERSION;
  readonly kind: BuildKind;
  readonly preset: PresetName | 'custom';
  readonly custom?: JsonObject;
  readonly seed: number;
  readonly crop?: CropRect;
}

function isKind(value: string): value is BuildKind {
  return (BUILD_KINDS as readonly string[]).includes(value);
}

function isPreset(value: string): value is PresetName {
  return (PRESET_NAMES as readonly string[]).includes(value);
}

/** Parses a build configuration document; throws InvalidDocumentError with the PHP message. */
export function parseBuildConfig(value: unknown): BuildConfig {
  const d = doc.object(value, 'config');
  doc.keys(d, ['$schema', 'format', 'version', 'kind', 'preset', 'custom', 'seed', 'crop'], 'config');
  if (d['format'] !== BUILD_CONFIG_FORMAT) {
    throw new InvalidDocumentError(`config.format must be "${BUILD_CONFIG_FORMAT}".`);
  }
  const version = doc.int(d, 'version', 'config', 1);
  if (version !== BUILD_CONFIG_VERSION) {
    throw new InvalidDocumentError(
      `Unsupported build configuration version ${version}; this library reads version ${BUILD_CONFIG_VERSION}.`,
    );
  }
  const kind = doc.string(d, 'kind', 'config', 32);
  if (!isKind(kind)) {
    throw new InvalidDocumentError(`config.kind must be one of ${BUILD_KINDS.join(', ')}.`);
  }
  let crop: CropRect | null = null;
  if (doc.has(d, 'crop') && d['crop'] !== null) {
    const c = doc.object(d['crop'], 'config.crop');
    doc.keys(c, ['x', 'y', 'width', 'height'], 'config.crop');
    crop = {
      x: doc.int(c, 'x', 'config.crop', 0, MAX_CROP),
      y: doc.int(c, 'y', 'config.crop', 0, MAX_CROP),
      width: doc.int(c, 'width', 'config.crop', 1, MAX_CROP),
      height: doc.int(c, 'height', 'config.crop', 1, MAX_CROP),
    };
  }
  const preset = doc.string(d, 'preset', 'config', 32);
  const custom = doc.has(d, 'custom') && d['custom'] !== null ? doc.object(d['custom'], 'config.custom') : {};
  const seed = doc.int(d, 'seed', 'config', 0, MAX_SEED);
  return createBuildConfig({ kind, preset, custom, seed, crop });
}

/** Validates the parts of a configuration (the constructor checks of the PHP class). */
export function createBuildConfig(parts: {
  kind: BuildKind;
  preset: string;
  custom?: JsonObject;
  seed?: number;
  crop?: CropRect | null;
}): BuildConfig {
  const custom = parts.custom ?? {};
  const seed = parts.seed ?? 0;
  const preset = parts.preset;
  if (preset !== 'custom' && !isPreset(preset)) {
    throw new InvalidDocumentError(`config.preset must be one of ${PRESET_NAMES.join(', ')} or "custom".`);
  }
  const hasCustom = Object.keys(custom).length > 0;
  if (preset !== 'custom' && hasCustom) {
    throw new InvalidDocumentError('config.custom requires the "custom" preset.');
  }
  if (preset === 'custom') {
    if (!hasCustom) {
      throw new InvalidDocumentError('config.custom is required with the "custom" preset.');
    }
    doc.keys(custom, CUSTOM_KEYS[parts.kind], 'config.custom');
  }
  const max = parts.kind === 'jigsaw' ? MAX_JIGSAW_SEED : MAX_SEED;
  if (!Number.isSafeInteger(seed) || seed < 0 || seed > max) {
    throw new InvalidDocumentError(`config.seed must be between 0 and ${max} for ${parts.kind}, got ${seed}.`);
  }
  return { kind: parts.kind, preset, custom, seed, crop: parts.crop ?? null };
}

export function buildConfigDocument(config: BuildConfig): BuildConfigDocument {
  return {
    format: BUILD_CONFIG_FORMAT,
    version: BUILD_CONFIG_VERSION,
    kind: config.kind,
    preset: config.preset,
    ...(Object.keys(config.custom).length > 0 ? { custom: config.custom } : {}),
    seed: config.seed,
    ...(config.crop !== null ? { crop: { x: config.crop.x, y: config.crop.y, width: config.crop.width, height: config.crop.height } } : {}),
  };
}

/** Board shape a configuration asks for, for previews: grid cells or strips. */
export type BuildShape =
  | { readonly type: 'grid'; readonly rows: number; readonly cols: number; readonly topology: string }
  | { readonly type: 'strips'; readonly count: number; readonly axis: 'horizontal' | 'vertical' }
  | { readonly type: 'tangram'; readonly target: string };

export function buildShape(config: BuildConfig): BuildShape {
  const params = config.preset === 'custom' ? config.custom : PRESET_SHAPES[config.kind][config.preset];
  const positive = (key: string, fallback: number): number => {
    const value = params[key];
    return typeof value === 'number' && Number.isSafeInteger(value) && value > 0 ? value : fallback;
  };
  if (config.kind === 'tangram') {
    return { type: 'tangram', target: typeof params['target'] === 'string' ? params['target'] : 'square' };
  }
  if (config.kind === 'strips') {
    return { type: 'strips', count: positive('count', 4), axis: params['axis'] === 'vertical' ? 'vertical' : 'horizontal' };
  }
  const topology = config.kind === 'jigsaw' && typeof params['topology'] === 'string' ? params['topology'] : 'rect';
  return { type: 'grid', rows: positive('rows', 3), cols: positive('cols', 3), topology };
}
