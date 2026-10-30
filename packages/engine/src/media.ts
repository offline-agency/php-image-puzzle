import * as doc from './doc.js';
import { InvalidDocumentError } from './errors.js';
import { parseRect } from './rect.js';
import type { GameKind, Media, PieceAtlas, PieceResolution, Rect, Tile } from './types.js';

export const MAX_RESOLUTIONS = 4;
export const MAX_ATLAS_SIZE = 16384;

const NAME_PATTERN = /^[a-z][a-z0-9-]{0,15}$/;
const SAFE_PATH = /^[A-Za-z0-9_][A-Za-z0-9._-]*(\/[A-Za-z0-9_][A-Za-z0-9._-]*)*$/;

/** Parses definition.media like PieceImages::fromMedia() in PHP (structure only; see validateMedia). */
export function parseMedia(value: unknown): Media {
  const media = doc.object(value, 'definition.media');
  doc.keys(media, ['image', 'resolutions', 'atlas'], 'definition.media');
  const image = doc.string(media, 'image', 'definition.media');
  const resolutions =
    media['resolutions'] === undefined
      ? []
      : doc
          .list(media['resolutions'], 'definition.media.resolutions', MAX_RESOLUTIONS)
          .map((item, i) => parseResolution(item, `definition.media.resolutions[${i}]`));
  if (media['resolutions'] !== undefined && resolutions.length === 0) {
    throw new InvalidDocumentError('definition.media.resolutions must not be empty when present.');
  }
  const names = new Set<string>();
  const directories = new Set<string>();
  for (const resolution of resolutions) {
    if (names.has(resolution.name) || directories.has(resolution.directory)) {
      throw new InvalidDocumentError(`media.resolutions repeats the name or directory of "${resolution.name}".`);
    }
    names.add(resolution.name);
    directories.add(resolution.directory);
  }
  const atlas = media['atlas'] === undefined ? undefined : parseAtlas(media['atlas'], 'definition.media.atlas');
  return {
    image,
    ...(resolutions.length > 0 ? { resolutions } : {}),
    ...(atlas !== undefined ? { atlas } : {}),
  };
}

/** Checks the alternative piece images against their definition (PieceImages::validate() in PHP). */
export function validateMedia(media: Media, kind: GameKind, sourceWidth: number, tiles: readonly Tile[]): void {
  if (media.resolutions === undefined && media.atlas === undefined) return;
  if (kind !== 'jigsaw') {
    throw new InvalidDocumentError('media.resolutions and media.atlas are only defined for jigsaw pieces.');
  }
  const first = tiles[0]?.attributes['image'];
  const fallback = typeof first === 'string' && first.includes('/') ? first.slice(0, first.lastIndexOf('/')) : '.';
  for (const resolution of media.resolutions ?? []) {
    if (resolution.directory === fallback) {
      throw new InvalidDocumentError(`Resolution ${resolution.name} cannot use the directory of the full-resolution tile images.`);
    }
    if (resolution.width >= sourceWidth) {
      throw new InvalidDocumentError(
        `Resolution ${resolution.name} (${resolution.width} px) must be narrower than the ${sourceWidth} px source; the tile images are the full resolution.`,
      );
    }
  }
  if (media.atlas !== undefined) {
    const ids = Object.keys(media.atlas.frames);
    if (ids.length !== tiles.length || ids.some((id, i) => tiles[i]?.id !== id)) {
      throw new InvalidDocumentError('media.atlas.frames must list every tile ID once, in tile order.');
    }
  }
}

/** URL path of a tile image in a resolution set: same file name, the set's directory. */
export function resolutionImage(resolution: PieceResolution, tileImage: string): string {
  return `${resolution.directory}/${tileImage.slice(tileImage.lastIndexOf('/') + 1)}`;
}

/** Pixel width of a piece image of `resolution` for `length` source pixels, as PHP rounds it. */
export function resolutionLength(resolution: PieceResolution, length: number, sourceWidth: number): number {
  return Math.max(1, Math.round((length * resolution.width) / sourceWidth));
}

function parseResolution(value: unknown, path: string): PieceResolution {
  const item = doc.object(value, path);
  doc.keys(item, ['name', 'width', 'directory'], path);
  const name = doc.string(item, 'name', path, 16);
  const width = doc.int(item, 'width', path, 1, 1 << 20);
  const directory = doc.string(item, 'directory', path, 255);
  if (!NAME_PATTERN.test(name)) {
    throw new InvalidDocumentError(`Resolution name "${name}" must match ${String(NAME_PATTERN)}.`);
  }
  if (!SAFE_PATH.test(directory)) {
    throw new InvalidDocumentError(`Resolution ${name} directory must be a safe relative path.`);
  }
  return { name, width, directory };
}

function parseAtlas(value: unknown, path: string): PieceAtlas {
  const item = doc.object(value, path);
  doc.keys(item, ['image', 'width', 'height', 'frames'], path);
  const image = doc.string(item, 'image', path, 255);
  if (!SAFE_PATH.test(image)) {
    throw new InvalidDocumentError('media.atlas.image must be a safe relative path.');
  }
  const width = doc.int(item, 'width', path, 1, MAX_ATLAS_SIZE);
  const height = doc.int(item, 'height', path, 1, MAX_ATLAS_SIZE);
  const frames: Record<string, Rect> = {};
  for (const [id, frame] of Object.entries(doc.object(item['frames'], `${path}.frames`))) {
    const rect = parseRect(frame, `${path}.frames.${doc.id(id, `${path}.frames`)}`);
    if (rect.x < 0 || rect.y < 0 || rect.x + rect.width > width || rect.y + rect.height > height) {
      throw new InvalidDocumentError(`media.atlas frame ${id} lies outside the ${width}x${height} atlas.`);
    }
    frames[id] = rect;
  }
  return { image, width, height, frames };
}
