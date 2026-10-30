import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';
import { drag, moves, openPlain, waitReady } from './support.js';

const board = '.image-puzzle-player svg.tangram';

interface Pose {
  x: number;
  y: number;
  rotation: number;
  flipped: boolean;
}

const poses = (page: Page): Promise<Pose[]> =>
  page.evaluate(() => (window.puzzlePlayer?.exportState().board as unknown as { pieces: Pose[] }).pieces);

const progress = (page: Page): Promise<number> => page.evaluate(() => window.puzzlePlayer?.snapshot().progress ?? -1);

/** World point at (ux, uy) units from the square's top-left corner. */
const at = (page: Page, ux: number, uy: number): Promise<{ x: number; y: number }> =>
  page.evaluate(
    ([px, py]) => {
      const definition = window.puzzlePlayer?.engine.definition;
      if (definition === null || definition === undefined) throw new Error('not loaded');
      const unit = definition.rules['unit'] as number;
      return { x: definition.crop.x + Math.round(px * unit), y: definition.crop.y + Math.round(py * unit) };
    },
    [ux, uy] as const,
  );

test.describe('tangram view', () => {
  test('draws the target silhouette and seven focusable pieces with the picture clipped to their outline', async ({ page }) => {
    await openPlain(page, 'tangram');
    await expect(page.locator(`${board} .target path`)).toHaveCount(7);
    const pieces = page.locator(`${board} g.piece`);
    await expect(pieces).toHaveCount(7);
    for (const piece of await pieces.all()) {
      await expect(piece).toHaveAttribute('role', 'button');
      await expect(piece).toHaveAttribute('tabindex', '0');
      await expect(piece).toHaveAttribute('aria-label', /, turned \d+°/);
      expect(await piece.locator('image').getAttribute('clip-path')).toMatch(/^url\(#ipp-tangram-/);
    }
  });

  test('keyboard only: move, turn and mirror a piece, then drop it into its place', async ({ page, isMobile }) => {
    test.skip(isMobile, 'hardware keyboard path is covered by the desktop projects');
    await openPlain(page, 'tangram');
    const square = page.locator(`${board} g.piece[data-piece="square"]`);
    const before = (await poses(page))[5] as Pose;
    await square.focus();
    await page.keyboard.press('r');
    expect(((await poses(page))[5] as Pose).rotation).toBe((before.rotation + 1) % 8);
    await page.keyboard.press('Shift+R');
    expect(((await poses(page))[5] as Pose).rotation).toBe(before.rotation);
    await page.keyboard.press('f');
    expect(((await poses(page))[5] as Pose).flipped).toBe(!before.flipped);
    expect(await moves(page)).toBe(3);
    // Arrow moves stay pending until Enter.
    await page.keyboard.press('ArrowLeft');
    expect(await moves(page)).toBe(3);
    await page.keyboard.press('Enter');
    expect(await moves(page)).toBe(4);

    // Put it 200 px right of its place (centroid at 3, 2 units) in the picture orientation, then walk it back.
    const slot = await at(page, 3, 2);
    await page.evaluate(
      ({ x, y }) => window.puzzlePlayer?.dispatchAction({ type: 'pose', piece: 'square', x: x + 200, y, rotation: 0, flipped: false }),
      slot,
    );
    await square.focus();
    for (let i = 0; i < 4; i++) await page.keyboard.press('Shift+ArrowLeft');
    await page.keyboard.press('Enter');
    expect((await poses(page))[5]).toEqual({ ...slot, rotation: 0, flipped: false });
    await expect(square).toHaveAttribute('aria-label', 'Square, in place');
    await expect(page.locator('.image-puzzle-player [aria-live]')).toHaveText(/Square, in place$/);
  });

  test('pointer: dragging a piece onto its place snaps it, the turn buttons act on the touched piece', async ({ page }) => {
    await openPlain(page, 'tangram');
    // The medium triangle's place has its centroid at (10/3, 10/3) units; park it, upright, on the empty top of the target.
    const target = await at(page, 10 / 3, 10 / 3);
    const parked = await at(page, 2, 1);
    expect(
      await page.evaluate(
        (pose) => window.puzzlePlayer?.dispatchAction({ type: 'pose', piece: 'medium', ...pose, rotation: 0, flipped: false }),
        parked,
      ),
    ).toEqual({ ok: true });
    const svg = page.locator(board);
    const box = await svg.boundingBox();
    if (box === null) throw new Error('no board box');
    const viewBox = ((await svg.getAttribute('viewBox')) ?? '').split(' ').map(Number);
    const [vx = 0, vy = 0, vw = 1, vh = 1] = viewBox;
    const screen = (x: number, y: number): { x: number; y: number } => ({
      x: box.x + ((x - vx) / vw) * box.width,
      y: box.y + ((y - vy) / vh) * box.height,
    });
    const from = (await poses(page))[2] as Pose;
    await drag(page, screen(from.x, from.y), screen(target.x + 10, target.y - 10));
    expect((await poses(page))[2]).toEqual({ x: target.x, y: target.y, rotation: 0, flipped: false });
    expect(await progress(page)).toBeGreaterThan(0);

    await page.locator('.image-puzzle-player [data-tool="cw"]').click();
    expect(((await poses(page))[2] as Pose).rotation).toBe(1);
    await page.locator('.image-puzzle-player [data-tool="flip"]').click();
    expect(((await poses(page))[2] as Pose).flipped).toBe(true);
  });

  test('the arrow silhouette completes by playing the shared hints', async ({ page }) => {
    await openPlain(page, 'tangram-arrow');
    const complete = await page.evaluate(() => {
      const player = window.puzzlePlayer;
      if (player === null) throw new Error('no player');
      for (let i = 0; i < 20 && player.engine.status !== 'complete'; i++) {
        const action = player.engine.hint();
        if (action !== null) player.dispatchAction(action);
      }
      return player.engine.status;
    });
    expect(complete).toBe('complete');
    await expect(page.locator(`${board} g.piece[tabindex="-1"]`)).toHaveCount(7);
  });

  test('a partly assembled game survives a reload', async ({ page }) => {
    await openPlain(page, 'tangram');
    await page.evaluate(() => {
      for (let i = 0; i < 3; i++) {
        const action = window.puzzlePlayer?.engine.hint();
        if (action !== null && action !== undefined) window.puzzlePlayer?.dispatchAction(action);
      }
      window.puzzlePlayer?.dispatchAction({ type: 'pose', piece: 'parallelogram', x: 500, y: 700, rotation: 3, flipped: true });
    });
    const saved = await poses(page);
    expect(await progress(page)).toBeCloseTo(3 / 7);
    await page.waitForTimeout(400); // autosave debounce
    await page.reload();
    await waitReady(page, 'sliding');
    await page.selectOption('#mode', 'tangram');
    await waitReady(page, 'tangram');
    expect(await poses(page)).toEqual(saved);
    await expect(page.locator(`${board} g.piece.is-placed`)).toHaveCount(3);
  });
});
