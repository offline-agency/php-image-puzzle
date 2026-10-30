// @vitest-environment node
import { renderToString } from 'react-dom/server';
import { describe, expect, it, vi } from 'vitest';
import { ImagePuzzlePlayer } from '../src/index.js';

describe('React server rendering', () => {
  it('renders the container without touching the DOM or warning', () => {
    expect(typeof (globalThis as { document?: unknown }).document).toBe('undefined');
    const error = vi.spyOn(console, 'error').mockImplementation(() => undefined);
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);
    try {
      const html = renderToString(<ImagePuzzlePlayer src="/puzzles/jigsaw.json" className="board" onComplete={() => undefined} />);
      expect(html).toBe('<div class="board"></div>');
      expect(error).not.toHaveBeenCalled();
      expect(warn).not.toHaveBeenCalled();
    } finally {
      error.mockRestore();
      warn.mockRestore();
    }
  });
});
