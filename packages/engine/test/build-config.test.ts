import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import {
  BUILD_CONFIG_FORMAT,
  BUILD_CONFIG_VERSION,
  BUILD_KINDS,
  CUSTOM_KEYS,
  InvalidDocumentError,
  MAX_JIGSAW_SEED,
  MAX_SEED,
  PRESET_NAMES,
  PRESET_SHAPES,
  buildConfigDocument,
  buildShape,
  createBuildConfig,
  parseBuildConfig,
} from '../src/index.js';

const dir = fileURLToPath(new URL('../../../conformance/', import.meta.url));
const read = (name: string): unknown => JSON.parse(readFileSync(dir + name, 'utf8'));

interface Fixture {
  format: string;
  version: number;
  maxSeed: number;
  maxJigsawSeed: number;
  customKeys: Record<string, string[]>;
  cases: { document: unknown; valid: boolean; normalized?: unknown; error?: string }[];
}

const fixture = read('build-config.json') as Fixture;
const presets = read('presets.json') as { presets: Record<string, Record<string, Record<string, unknown>>> };

describe('build configuration conformance with PHP', () => {
  it('shares the format, version, modes, custom keys and seed range', () => {
    expect(BUILD_CONFIG_FORMAT).toBe(fixture.format);
    expect(BUILD_CONFIG_VERSION).toBe(fixture.version);
    expect(MAX_SEED).toBe(fixture.maxSeed);
    expect(MAX_JIGSAW_SEED).toBe(fixture.maxJigsawSeed);
    expect([...BUILD_KINDS]).toEqual(Object.keys(fixture.customKeys));
    expect(CUSTOM_KEYS).toEqual(fixture.customKeys);
  });

  it('copies the board shape of every PHP preset', () => {
    for (const kind of BUILD_KINDS) {
      for (const name of PRESET_NAMES) {
        const php = presets.presets[kind]?.[name] ?? {};
        for (const [key, value] of Object.entries(PRESET_SHAPES[kind][name])) {
          expect(php[key], `${kind}.${name}.${key}`).toEqual(value);
        }
      }
    }
  });

  it.each(fixture.cases.map((c, i) => [i, c] as const))('case %i matches the PHP verdict', (_, c) => {
    if (c.valid) {
      expect(buildConfigDocument(parseBuildConfig(c.document))).toEqual(c.normalized);
    } else {
      expect(() => parseBuildConfig(c.document)).toThrow(InvalidDocumentError);
      expect(() => parseBuildConfig(c.document)).toThrow(c.error);
    }
  });
});

describe('build shapes for previews', () => {
  it('reads presets and custom parameters', () => {
    expect(buildShape(createBuildConfig({ kind: 'jigsaw', preset: 'medium' }))).toEqual({
      type: 'grid',
      rows: 6,
      cols: 8,
      topology: 'rect',
    });
    expect(buildShape(createBuildConfig({ kind: 'jigsaw', preset: 'custom', custom: { rows: 2, cols: 5, topology: 'hex' } }))).toEqual({
      type: 'grid',
      rows: 2,
      cols: 5,
      topology: 'hex',
    });
    expect(buildShape(createBuildConfig({ kind: 'strips', preset: 'hard' }))).toEqual({ type: 'strips', count: 14, axis: 'vertical' });
  });
});
