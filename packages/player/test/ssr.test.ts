// @vitest-environment node
import { describe, expect, it } from 'vitest';

/* Importing every entry in a server runtime (no window/document/HTMLElement) must not throw or register anything. */
describe('SSR-safe entries', () => {
  it('imports the main, define and browser entries without DOM globals', async () => {
    expect(typeof (globalThis as { HTMLElement?: unknown }).HTMLElement).toBe('undefined');
    const main = await import('../src/index.js');
    const define = await import('../src/define.js');
    await import('../src/browser.js');
    expect(main.defineImagePuzzlePlayer()).toBeUndefined();
    expect(define.ImagePuzzlePlayerElement).toBeUndefined();
    expect(typeof main.createPlayer).toBe('function');
  });
});
