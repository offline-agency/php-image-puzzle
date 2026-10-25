import { createHash } from 'node:crypto';
import { describe, expect, it } from 'vitest';
import { canonicalJson, sha256Hex } from '../src/index.js';

describe('canonical JSON and SHA-256', () => {
  it('matches the NIST vectors and node:crypto', () => {
    expect(sha256Hex('')).toBe('e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855');
    expect(sha256Hex('abc')).toBe('ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad');
    for (const length of [55, 56, 63, 64, 65, 119, 120, 1000]) {
      const text = 'x'.repeat(length) + 'ü';
      expect(sha256Hex(text)).toBe(createHash('sha256').update(text).digest('hex'));
    }
  });

  it('sorts keys at every depth and keeps list order', () => {
    expect(canonicalJson({ b: 1, a: { d: [3, 1], c: null } })).toBe('{"a":{"c":null,"d":[3,1]},"b":1}');
    expect(canonicalJson({ $schema: 1, Z: 2, a: 3 })).toBe('{"$schema":1,"Z":2,"a":3}');
  });
});
