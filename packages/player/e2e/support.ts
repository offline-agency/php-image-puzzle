import { expect } from '@playwright/test';
import type { Page } from '@playwright/test';
import type { Player } from '../src/index.js';

export const MODES = ['sliding', 'swap', 'rotation', 'strips', 'jigsaw', 'jigsaw-hex'] as const;
/** PHP builds with alternative piece images (examples/player/assets/<name>/definition.json). */
export const ASSET_BUILDS = ['jigsaw-responsive/definition', 'jigsaw-atlas/definition'] as const;
export type Mode = (typeof MODES)[number] | (typeof ASSET_BUILDS)[number];

declare global {
  interface Window {
    puzzlePlayer: Player | null;
  }
}

/**
 * Opens the plain-JS example on a mode and waits until the board and its
 * images are ready. `css` is added to the page head before any script runs.
 */
export async function openPlain(page: Page, mode: Mode = 'sliding', css?: string): Promise<void> {
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  if (css !== undefined) {
    await page.route('**/examples/player/index.html', async (route) => {
      const response = await route.fetch();
      await route.fulfill({ response, body: (await response.text()).replace('</head>', `<style>${css}</style></head>`) });
    });
  }
  await page.goto('examples/player/index.html');
  await waitReady(page, 'sliding');
  if (mode !== 'sliding') {
    await page.selectOption('#mode', mode);
    await waitReady(page, mode);
  }
  expect(errors).toEqual([]);
}

export async function waitReady(page: Page, mode: Mode): Promise<void> {
  const kind = mode.startsWith('jigsaw') ? 'jigsaw' : mode;
  await page.waitForFunction((k) => window.puzzlePlayer?.view === 'ready' && window.puzzlePlayer.engine.definition?.kind === k, kind);
  await expect(page.locator('.image-puzzle-player .board')).toHaveAttribute('data-loading', 'false');
}

export function moves(page: Page): Promise<number> {
  return page.evaluate(() => window.puzzlePlayer?.snapshot().moves ?? -1);
}

/** Drags with real pointer events (mouse) through intermediate points. */
export async function drag(page: Page, from: { x: number; y: number }, to: { x: number; y: number }): Promise<void> {
  await page.mouse.move(from.x, from.y);
  await page.mouse.down();
  await page.mouse.move(from.x + (to.x - from.x) / 4, from.y + (to.y - from.y) / 4, { steps: 4 });
  await page.mouse.move(to.x, to.y, { steps: 8 });
  await page.mouse.up();
}
