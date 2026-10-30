// @vitest-environment node
import { createSSRApp, h } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { describe, expect, it, vi } from 'vitest';
import { ImagePuzzlePlayer } from '../src/index.js';

describe('Vue server rendering', () => {
  it('renders the container without touching the DOM or warning', async () => {
    expect(typeof (globalThis as { document?: unknown }).document).toBe('undefined');
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);
    const error = vi.spyOn(console, 'error').mockImplementation(() => undefined);
    try {
      const app = createSSRApp({
        render: () => h(ImagePuzzlePlayer, { src: '/puzzles/jigsaw.json', class: 'board', onComplete: () => undefined }),
      });
      expect(await renderToString(app)).toBe('<div class="board"></div>');
      expect(warn).not.toHaveBeenCalled();
      expect(error).not.toHaveBeenCalled();
    } finally {
      warn.mockRestore();
      error.mockRestore();
    }
  });
});
