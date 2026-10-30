import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { InvalidDocumentError, definitionDocument, parseDefinition, resolutionImage, resolutionLength } from '../src/index.js';

const dir = fileURLToPath(new URL('../../../conformance/', import.meta.url));

interface Case {
  name: string;
  document: unknown;
  valid: boolean;
  id?: string;
  error?: string;
}

const fixture = JSON.parse(readFileSync(dir + 'media.json', 'utf8')) as { cases: Case[] };

describe('definition.media conformance with PHP', () => {
  it.each(fixture.cases.map((c) => [c.name, c] as const))('%s matches the PHP verdict', (_, c) => {
    if (c.valid) {
      const definition = parseDefinition(c.document);
      expect(definition.id).toBe(c.id);
      // Round trip: the document TypeScript writes is the one PHP wrote.
      expect(definitionDocument(definition)['media']).toEqual((c.document as { media: unknown }).media);
    } else {
      expect(() => parseDefinition(c.document)).toThrow(InvalidDocumentError);
      expect(() => parseDefinition(c.document)).toThrow(c.error);
    }
  });

  it('covers valid and rejected documents', () => {
    expect(fixture.cases.filter((c) => c.valid).length).toBeGreaterThanOrEqual(3);
    expect(fixture.cases.filter((c) => !c.valid).length).toBeGreaterThanOrEqual(10);
  });
});

describe('resolution helpers', () => {
  const thumbnail = { name: 'thumbnail', width: 160, directory: 'pieces-thumbnail' };

  it('keeps the tile file name in the set directory', () => {
    expect(resolutionImage(thumbnail, 'pieces/piece_0_1.webp')).toBe('pieces-thumbnail/piece_0_1.webp');
    expect(resolutionImage(thumbnail, 'piece.png')).toBe('pieces-thumbnail/piece.png');
  });

  it('scales lengths like PHP and never below one pixel', () => {
    expect(resolutionLength(thumbnail, 213, 640)).toBe(53);
    expect(resolutionLength(thumbnail, 2, 640)).toBe(1);
    expect(resolutionLength(thumbnail, 6, 640)).toBe(2);
  });
});
