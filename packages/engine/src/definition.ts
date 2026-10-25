import { canonicalJson, sha256Hex } from './canonical.js';
import * as doc from './doc.js';
import { InvalidDocumentError } from './errors.js';
import { parseMedia, validateMedia } from './media.js';
import { MAX_SEED } from './prng.js';
import { parseRect } from './rect.js';
import { rulesFor } from './rules/index.js';
import type { DefinitionCore } from './rules/index.js';
import { GAME_KINDS } from './types.js';
import type { Assistance, GameDefinition, GameKind, Rect, Tile } from './types.js';

export const DEFINITION_FORMAT = 'image-puzzle/game-definition';
export const DEFINITION_VERSION = 1;
export const GENERATOR_ALGORITHM = 1;
export const MAX_DEFINITION_BYTES = 4 * 1024 * 1024;
export const MAX_TILES = 4096;
export const DEFINITION_SCHEMA_ID =
  'https://raw.githubusercontent.com/offline-agency/php-image-puzzle/HEAD/docs/schemas/game/v1/definition.schema.json';

const MEDIA_PATTERN = /^(?:https:\/\/[A-Za-z0-9._~:/?#[\]@!$&'()*+,;=%-]+|[A-Za-z0-9_~-][A-Za-z0-9._~/-]*)$/;
const DOT_SEGMENT = /(^|\/)\.\.?(\/|$)/;
const TOP_LEVEL = [
  '$schema',
  'format',
  'version',
  'id',
  'kind',
  'generator',
  'seed',
  'preset',
  'source',
  'media',
  'board',
  'tiles',
  'rules',
  'capabilities',
  'actions',
  'assistance',
  'initial',
];

/** Parses JSON text with the same size limit as PHP. */
export function parseDefinitionJson(json: string): GameDefinition {
  if (new TextEncoder().encode(json).length > MAX_DEFINITION_BYTES) {
    throw new InvalidDocumentError(`Game definition exceeds ${MAX_DEFINITION_BYTES} bytes.`);
  }
  let data: unknown;
  try {
    data = JSON.parse(json);
  } catch (error) {
    throw new InvalidDocumentError(`Game definition is not valid JSON: ${error instanceof Error ? error.message : String(error)}`);
  }
  return parseDefinition(data);
}

/**
 * Validates an external definition exactly like GameDefinition::fromArray()
 * in PHP, including the content-bound id. Returns a frozen copy that shares
 * nothing with the input.
 */
export function parseDefinition(data: unknown): GameDefinition {
  const root = doc.object(data, 'definition');
  doc.keys(root, TOP_LEVEL, 'definition');
  if (root['format'] !== DEFINITION_FORMAT) {
    throw new InvalidDocumentError(`definition.format must be "${DEFINITION_FORMAT}".`);
  }
  const version = doc.int(root, 'version', 'definition', 1);
  if (version !== DEFINITION_VERSION) {
    throw new InvalidDocumentError(`Unsupported game definition version ${version}; this library reads version ${DEFINITION_VERSION}.`);
  }
  const kindName = doc.string(root, 'kind', 'definition', 32);
  if (!(GAME_KINDS as readonly string[]).includes(kindName)) {
    throw new InvalidDocumentError('definition.kind is not a supported game kind.');
  }
  const kind = kindName as GameKind;
  const generator = doc.object(root['generator'], 'definition.generator');
  doc.keys(generator, ['algorithm'], 'definition.generator');
  const algorithm = doc.int(generator, 'algorithm', 'definition.generator', 1);
  if (algorithm !== GENERATOR_ALGORITHM) {
    throw new InvalidDocumentError(`Unsupported generator algorithm ${algorithm}.`);
  }
  const preset = doc.object(root['preset'], 'definition.preset');
  doc.keys(preset, ['name', 'version'], 'definition.preset');
  const source = doc.object(root['source'], 'definition.source');
  doc.keys(source, ['width', 'height', 'sha256'], 'definition.source');
  const sha = source['sha256'] ?? null;
  if (sha !== null && (typeof sha !== 'string' || !/^[0-9a-f]{64}$/.test(sha))) {
    throw new InvalidDocumentError('definition.source.sha256 must be null or 64 lowercase hex digits.');
  }
  const media = parseMedia(root['media']);
  const board = doc.object(root['board'], 'definition.board');
  doc.keys(board, ['rows', 'cols', 'crop'], 'definition.board');
  const tiles = doc.list(root['tiles'], 'definition.tiles', MAX_TILES).map((value, i) => parseTile(value, `definition.tiles[${i}]`));
  const assistance = doc.object(root['assistance'], 'definition.assistance');
  doc.keys(assistance, ['preview', 'numbers', 'highlightCorrect'], 'definition.assistance');
  const rules = doc.object(root['rules'], 'definition.rules');

  const core: DefinitionCore = {
    kind,
    seed: doc.int(root, 'seed', 'definition', 0, MAX_SEED),
    preset: { name: doc.string(preset, 'name', 'definition.preset', 32), version: doc.int(preset, 'version', 'definition.preset', 1) },
    source: {
      width: doc.int(source, 'width', 'definition.source', 1, 1 << 20),
      height: doc.int(source, 'height', 'definition.source', 1, 1 << 20),
      sha256: sha,
    },
    media,
    rows: doc.int(board, 'rows', 'definition.board', 1, 1024),
    cols: doc.int(board, 'cols', 'definition.board', 1, 1024),
    crop: parseRect(board['crop'], 'definition.board.crop'),
    tiles,
    rules: structuredClone(rules),
    assistance: {
      preview: doc.bool(assistance, 'preview', 'definition.assistance'),
      numbers: doc.bool(assistance, 'numbers', 'definition.assistance'),
      highlightCorrect: doc.bool(assistance, 'highlightCorrect', 'definition.assistance'),
    } satisfies Assistance,
  };
  const id = doc.string(root, 'id', 'definition', 80);
  validateCommon(core);
  const mode = rulesFor(kind);
  mode.validateDefinition(core);
  // Key order matters, as with PHP's strict array comparison.
  if (JSON.stringify(root['capabilities']) !== JSON.stringify(mode.capabilities)) {
    throw new InvalidDocumentError(`definition.capabilities do not match the ${kind} rules.`);
  }
  if (JSON.stringify(root['actions']) !== JSON.stringify(mode.actionTypes)) {
    throw new InvalidDocumentError(`definition.actions do not match the ${kind} rules.`);
  }
  const initial = mode.validateBoard(core, root['initial']);
  if (mode.isComplete(core, initial)) {
    throw new InvalidDocumentError('definition.initial is already solved.');
  }
  const definition: GameDefinition = {
    ...core,
    id,
    actions: [...mode.actionTypes],
    capabilities: { ...mode.capabilities },
    initial,
  };
  const expected = definitionId(definition);
  if (expected !== id) {
    throw new InvalidDocumentError(`definition.id does not match its content (expected ${expected}).`);
  }
  return deepFreeze(definition);
}

/** gd1- + SHA-256 of the canonical content (every field except $schema and id). */
export function definitionId(definition: GameDefinition): string {
  return `gd1-${sha256Hex(canonicalJson(definitionContent(definition)))}`;
}

/** The definition as a JSON document (what PHP's toArray() returns). */
export function definitionDocument(definition: GameDefinition): Record<string, unknown> {
  return { id: definition.id, $schema: DEFINITION_SCHEMA_ID, ...definitionContent(definition) };
}

function definitionContent(definition: GameDefinition): Record<string, unknown> {
  return {
    format: DEFINITION_FORMAT,
    version: DEFINITION_VERSION,
    kind: definition.kind,
    generator: { algorithm: GENERATOR_ALGORITHM },
    seed: definition.seed,
    preset: definition.preset,
    source: definition.source,
    media: definition.media,
    board: { rows: definition.rows, cols: definition.cols, crop: definition.crop },
    tiles: definition.tiles.map((tile) => ({ id: tile.id, home: tile.home, rect: tile.rect, ...tile.attributes })),
    rules: definition.rules,
    actions: definition.actions,
    capabilities: definition.capabilities,
    assistance: definition.assistance,
    initial: definition.initial,
  };
}

function parseTile(value: unknown, path: string): Tile {
  const tile = doc.object(value, path);
  const id = doc.id(tile['id'], `${path}.id`);
  const home = doc.int(tile, 'home', path, 0, 1 << 20);
  const rect: Rect = parseRect(tile['rect'], `${path}.rect`);
  const attributes: Record<string, unknown> = {};
  for (const [key, item] of Object.entries(tile)) {
    if (key !== 'id' && key !== 'home' && key !== 'rect') {
      attributes[key] = structuredClone(item);
    }
  }
  return { id, home, rect, attributes };
}

function validateCommon(definition: DefinitionCore): void {
  if (!/^(easy|medium|hard|custom)$/.test(definition.preset.name)) {
    throw new InvalidDocumentError(`Unknown preset "${definition.preset.name}".`);
  }
  if (!MEDIA_PATTERN.test(definition.media.image) || DOT_SEGMENT.test(definition.media.image)) {
    throw new InvalidDocumentError('media.image must be a relative path without dot segments or an https URL.');
  }
  const { crop, source } = definition;
  if (crop.x < 0 || crop.y < 0 || crop.x + crop.width > source.width || crop.y + crop.height > source.height) {
    throw new InvalidDocumentError('board.crop must lie inside the source image.');
  }
  const seen = new Set<string>();
  for (const tile of definition.tiles) {
    if (seen.has(tile.id)) {
      throw new InvalidDocumentError(`Duplicate tile id "${tile.id}".`);
    }
    seen.add(tile.id);
  }
  validateMedia(definition.media, definition.kind, definition.source.width, definition.tiles);
}

function deepFreeze<T>(value: T): T {
  if (typeof value === 'object' && value !== null && !Object.isFrozen(value)) {
    Object.freeze(value);
    for (const item of Object.values(value)) {
      deepFreeze(item);
    }
  }
  return value;
}
