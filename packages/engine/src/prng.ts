import { PuzzleError } from './errors.js';

/**
 * Portable deterministic generator identical to src/Game/Prng.php:
 * FNV-1a-32(stream) XOR seed, then mulberry32; int(n) = floor(u * n / 2^32).
 */
export const MAX_SEED = 0xffffffff;
export const MAX_RANGE = 1 << 20;

export function fnv1a(text: string): number {
  let hash = 0x811c9dc5;
  for (const byte of new TextEncoder().encode(text)) {
    hash = Math.imul(hash ^ byte, 0x01000193) >>> 0;
  }
  return hash >>> 0;
}

export class Prng {
  private state: number;

  constructor(seed: number, stream: string) {
    if (!Number.isInteger(seed) || seed < 0 || seed > MAX_SEED) {
      throw new PuzzleError('invalid', 'Seed must be an unsigned 32-bit integer.');
    }
    this.state = (fnv1a(stream) ^ seed) >>> 0;
  }

  nextUint32(): number {
    this.state = (this.state + 0x6d2b79f5) >>> 0;
    let t = this.state;
    t = Math.imul(t ^ (t >>> 15), t | 1);
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
    return (t ^ (t >>> 14)) >>> 0;
  }

  int(n: number): number {
    if (!Number.isInteger(n) || n < 1 || n > MAX_RANGE) {
      throw new PuzzleError('invalid', `Range must be between 1 and ${MAX_RANGE}.`);
    }
    // u * n < 2^52, so the product and the division by 2^32 are exact.
    return Math.floor((this.nextUint32() * n) / 4294967296);
  }

  shuffle<T>(items: readonly T[]): T[] {
    const out = [...items];
    for (let i = out.length - 1; i > 0; i--) {
      const j = this.int(i + 1);
      const a = out[i] as T;
      out[i] = out[j] as T;
      out[j] = a;
    }
    return out;
  }
}
