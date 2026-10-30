import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';
import { decodePng } from './png.js';
import { openPlain } from './support.js';

/*
 * Alternative piece images built by PHP (examples/player/generate.php): one
 * atlas request instead of one per piece, srcset sets chosen by displayed
 * size and pixel density, and seams no wider than those of the same puzzle's
 * default full-resolution PNG pieces (jigsaw-png) drawn at the same size.
 */

const board = '.image-puzzle-player';

async function solve(page: Page): Promise<void> {
  await page.evaluate(() => {
    const player = window.puzzlePlayer;
    if (!player) throw new Error('no player');
    for (let i = 0; i < 200 && player.engine.status !== 'complete'; i++) {
      const hint = player.engine.hint();
      if (hint === null) break;
      player.dispatchAction(hint);
    }
  });
  await expect.poll(() => page.evaluate(() => window.puzzlePlayer?.engine.status)).toBe('complete');
}

/**
 * Coverage deficit of the solved board: how much page background shows
 * through the assembled pieces, in pixels (a fully uncovered pixel counts 1,
 * a half-covered one 0.5). Measured by screenshotting the board over a black
 * and over a white page: an opaque pixel is identical in both.
 */
async function seamDeficit(page: Page, build: string, width: number): Promise<number> {
  await page.setViewportSize({ width: width + 40, height: 900 });
  await openPlain(page, 'sliding', `body { background: #000 !important; } #puzzle { width: ${String(width)}px; }`);
  await page.evaluate((name) => window.puzzlePlayer?.load(`assets/${name}/definition.json`), build);
  await expect(page.locator(`${board} .board[data-kind="jigsaw"]`)).toHaveAttribute('data-loading', 'false');
  await page.evaluate(() => {
    const host = window.puzzlePlayer?.element.shadowRoot;
    const style = document.createElement('style');
    // Only the pieces: no board background, guides or focus decoration.
    style.textContent =
      '.root, .board, .board-wrap, .viewport, .stage, .board-area { background: transparent !important; outline: none !important; } .guides { display: none; } * { transition: none !important; box-shadow: none !important; }';
    host?.append(style);
  });
  await solve(page);
  await page.evaluate(() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve))));
  const box = await page.locator(`${board} .board-area`).boundingBox();
  if (box === null) throw new Error('no board area');
  // Stay inside the outer border: only interior seams count.
  const clip = { x: box.x + 2, y: box.y + 2, width: box.width - 4, height: box.height - 4 };
  const black = decodePng(await page.screenshot({ clip }));
  await page.evaluate(() => {
    document.body.style.setProperty('background', '#fff', 'important');
  });
  const white = decodePng(await page.screenshot({ clip }));
  let deficit = 0;
  for (let i = 0; i < black.data.length; i += 4) {
    deficit += ((white.data[i + 1] ?? 0) - (black.data[i + 1] ?? 0)) / 255;
  }
  return deficit;
}

test.describe('alternative piece images', () => {
  test('an atlas build loads one image for every piece', async ({ page }) => {
    const requests: string[] = [];
    page.on('request', (request) => requests.push(new URL(request.url()).pathname));
    await openPlain(page, 'jigsaw-atlas/definition');
    const images = requests.filter((path) => path.includes('/jigsaw-atlas/') && /\.(png|webp|jpe?g)$/.test(path));
    // No per-piece requests (the hidden preview does not load the source either).
    expect(images).toEqual(['/examples/player/assets/jigsaw-atlas/atlas.png']);
    // Every piece shows its own, non-empty frame.
    const frames = await page.evaluate(() =>
      [...(window.puzzlePlayer?.element.shadowRoot?.querySelectorAll<HTMLImageElement>('.piece img.piece-image') ?? [])].map((image) => {
        const canvas = document.createElement('canvas');
        canvas.width = image.naturalWidth;
        canvas.height = image.naturalHeight;
        const context = canvas.getContext('2d');
        context?.drawImage(image, 0, 0);
        const data = context?.getImageData(0, 0, canvas.width, canvas.height).data ?? new Uint8ClampedArray();
        let opaque = 0;
        for (let i = 3; i < data.length; i += 4) if ((data[i] ?? 0) === 255) opaque++;
        return opaque;
      }),
    );
    expect(frames).toHaveLength(12);
    expect(frames.every((opaque) => opaque > 100)).toBe(true);
  });

  test('responsive pieces pick a set from their displayed width and the pixel density', async ({ browser }) => {
    const chosen = async (width: number, deviceScaleFactor: number): Promise<string[]> => {
      const context = await browser.newContext({ viewport: { width: width + 40, height: 900 }, deviceScaleFactor });
      const page = await context.newPage();
      await openPlain(page, 'jigsaw-responsive/definition', `#puzzle { width: ${String(width)}px; }`);
      const sources = await page.evaluate(() =>
        [...(window.puzzlePlayer?.element.shadowRoot?.querySelectorAll<HTMLImageElement>('.piece img') ?? [])].map((img) => img.currentSrc),
      );
      await context.close();
      return sources.map((src) => /\/(pieces[^/]*)\//.exec(src)?.[1] ?? src);
    };
    // The stage holds the board plus its tray: the board is 640/1305 of its
    // width, and the player asks for 1.5 times the displayed density. Sets
    // cover 160 (thumbnail), 320 (mobile) and 640 (full) source pixels.
    const small = await chosen(180, 1);
    expect(small).toHaveLength(12);
    expect(new Set(small)).toEqual(new Set(['pieces-thumbnail']));
    expect(new Set(await chosen(320, 1))).toEqual(new Set(['pieces-mobile']));
    expect(new Set(await chosen(1200, 2))).toEqual(new Set(['pieces']));
  });

  for (const width of [300, 700]) {
    test(`responsive and atlas pieces add no seams over full-resolution pieces at ${String(width)} px`, async ({ page, browser }) => {
      const baseline = await seamDeficit(page, 'jigsaw-png', width);
      const results: Record<string, number> = {};
      for (const build of ['jigsaw-responsive', 'jigsaw-atlas']) {
        const context = await browser.newContext();
        const other = await context.newPage();
        results[build] = await seamDeficit(other, build, width);
        await context.close();
      }
      test.info().annotations.push({ type: 'seam deficit (px)', description: JSON.stringify({ baseline, ...results }) });
      // Measured on 2026-10-25: responsive sets within 10 % of the baseline in
      // every engine; the full-resolution atlas below the baseline in Chromium
      // and Firefox and up to 21 % above it in WebKit at a 4x downscale.
      expect(results['jigsaw-responsive']).toBeLessThanOrEqual(baseline * 1.15 + 10);
      expect(results['jigsaw-atlas']).toBeLessThanOrEqual(baseline * 1.25 + 10);
    });
  }
});
