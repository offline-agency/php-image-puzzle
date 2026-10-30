import { expect, test } from '@playwright/test';
import { ASSET_BUILDS, MODES, drag, moves, openPlain, waitReady } from './support.js';

const board = '.image-puzzle-player .board';

test.describe('plain JavaScript player (examples/player/index.html)', () => {
  for (const mode of MODES) {
    test(`${mode}: loads over HTTP and completes with the event and announcement`, async ({ page }) => {
      await openPlain(page, mode);
      const result = await page.evaluate(() => {
        const player = window.puzzlePlayer;
        if (player === null) throw new Error('no player');
        const events: string[] = [];
        player.subscribe('complete', () => events.push('complete'));
        const definition = player.engine.definition;
        if (definition === null) throw new Error('not loaded');
        if (definition.kind === 'sliding') {
          // No hints in sliding: import a position one move from solved, then finish it.
          const cells: (string | null)[] = definition.tiles.map((tile) => tile.id);
          const last = cells.length - 1;
          cells[last] = cells[last - 1] ?? null;
          cells[last - 1] = null;
          player.importState({ ...player.exportState(), board: { cells }, history: { past: [], future: [] }, completed: false });
          player.dispatchAction({ type: 'slide', tile: cells[last] });
        } else if (definition.kind === 'memory') {
          // No hints in memory: flip both cards of every pair.
          for (const tile of definition.tiles) {
            if (player.engine.status !== 'complete' && tile.id.endsWith('-a')) {
              player.dispatchAction({ type: 'flip', card: tile.id });
              player.dispatchAction({ type: 'flip', card: tile.id.replace(/-a$/, '-b') });
            }
          }
        } else {
          for (let i = 0; i < 500 && player.engine.status !== 'complete'; i++) {
            const action = player.engine.hint();
            if (action !== null) player.dispatchAction(action);
          }
        }
        return { events, status: player.engine.status };
      });
      expect(result).toEqual({ events: ['complete'], status: 'complete' });
      await expect(page.locator('.image-puzzle-player [aria-live]')).toHaveText(/^Puzzle complete in \d+ moves and \d+:\d\d\.$/);
      await expect(page.locator('.image-puzzle-player [data-control="start"]')).toBeDisabled();
    });
  }

  test('sliding: click/tap a tile next to the gap', async ({ page, isMobile }) => {
    await openPlain(page, 'sliding');
    const id = await page.evaluate(() => (window.puzzlePlayer?.engine.legalActions()[0] as { tile: string }).tile);
    const tile = page.locator(`${board} [data-tile="${id}"]`);
    if (isMobile) await tile.tap();
    else await tile.click();
    expect(await moves(page)).toBe(1);
    await expect(page.locator('.image-puzzle-player [data-status="moves"]')).toHaveText('Moves: 1');
  });

  test('sliding: keyboard only (focus, arrows, Enter, undo)', async ({ page, isMobile }) => {
    test.skip(isMobile, 'hardware keyboard path is covered by the desktop projects');
    await openPlain(page, 'sliding');
    await page.locator(`${board} button.tile[tabindex="0"]`).focus();
    const before = await page.evaluate(() => window.puzzlePlayer?.exportState().board);
    for (const key of ['ArrowRight', 'ArrowDown', 'ArrowLeft', 'ArrowUp']) {
      await page.keyboard.press(key);
      await page.keyboard.press('Enter');
    }
    expect(await moves(page)).toBeGreaterThan(0);
    // Exactly one element is in the tab sequence and it is focused.
    await expect(page.locator(`${board} button.tile[tabindex="0"]`)).toHaveCount(1);
    await expect(page.locator(`${board} button.tile[tabindex="0"]`)).toBeFocused();
    await page.locator('.image-puzzle-player [data-control="reset"]').click();
    expect(await page.evaluate(() => window.puzzlePlayer?.exportState().board)).toEqual(before);
  });

  test('swap: drag one tile onto another, or tap both', async ({ page, isMobile }) => {
    await openPlain(page, 'swap');
    const [a, b] = await page.evaluate(() => (window.puzzlePlayer?.exportState().board as { cells: string[] }).cells.slice(0, 2));
    const first = page.locator(`${board} [data-tile="${String(a)}"]`);
    const second = page.locator(`${board} [data-tile="${String(b)}"]`);
    if (isMobile) {
      await first.tap();
      await second.tap();
    } else {
      const from = await first.boundingBox();
      const to = await second.boundingBox();
      if (from === null || to === null) throw new Error('no layout');
      await drag(page, { x: from.x + from.width / 2, y: from.y + from.height / 2 }, { x: to.x + to.width / 2, y: to.y + to.height / 2 });
    }
    const cells = await page.evaluate(() => (window.puzzlePlayer?.exportState().board as { cells: string[] }).cells.slice(0, 2));
    expect(cells).toEqual([b, a]);
  });

  test('rotation: click rotates clockwise, Shift+click counter-clockwise', async ({ page, isMobile }) => {
    await openPlain(page, 'rotation');
    const orientation = (): Promise<number> =>
      page.evaluate(() => (window.puzzlePlayer?.exportState().board as { orientations: number[] }).orientations[0] ?? -1);
    const before = await orientation();
    const tile = page.locator(`${board} [data-tile="t0"]`);
    if (isMobile) await tile.tap();
    else await tile.click();
    expect(await orientation()).toBe((before + 90) % 360);
    if (!isMobile) {
      await tile.click({ modifiers: ['Shift'] });
      expect(await orientation()).toBe(before);
    }
  });

  test('strips: drag a strip to another slot, or pick and drop with taps', async ({ page, isMobile }) => {
    await openPlain(page, 'strips');
    const order = await page.evaluate(() => (window.puzzlePlayer?.exportState().board as { order: string[] }).order);
    const first = page.locator(`${board} [data-tile="${String(order[0])}"]`);
    const third = page.locator(`${board} [data-tile="${String(order[2])}"]`);
    if (isMobile) {
      await first.tap();
      await third.tap();
    } else {
      const from = await first.boundingBox();
      const to = await third.boundingBox();
      if (from === null || to === null) throw new Error('no layout');
      await drag(page, { x: from.x + from.width / 2, y: from.y + from.height / 2 }, { x: to.x + to.width / 2, y: to.y + to.height / 2 });
    }
    const after = await page.evaluate(() => (window.puzzlePlayer?.exportState().board as { order: string[] }).order);
    expect(after.indexOf(String(order[0]))).toBe(2);
  });

  for (const mode of ['jigsaw', 'jigsaw-hex', 'jigsaw-triangle', ...ASSET_BUILDS] as const) {
    test(`${mode}: drag a piece by its outline onto its slot`, async ({ page, isMobile }) => {
      test.skip(isMobile, 'Playwright cannot synthesise touch drags; touch dragging is in the manual matrix');
      await openPlain(page, mode);
      const points = await page.evaluate(() => {
        const player = window.puzzlePlayer;
        const definition = player?.engine.definition;
        if (!player || !definition) throw new Error('not loaded');
        // The last piece in DOM order is on top of the scattered tray.
        const index = definition.tiles.length - 1;
        const tile = definition.tiles[index];
        if (tile === undefined) throw new Error('no tile');
        const rendered = tile.attributes['rendered'] as { x: number; y: number; width: number; height: number };
        const area = definition.rules['area'] as { x: number; y: number; width: number; height: number };
        const host = player.element.shadowRoot;
        const stage = host?.querySelector('.stage')?.getBoundingClientRect();
        const piece = host?.querySelector(`[data-piece="${tile.id}"]`)?.getBoundingClientRect();
        if (!stage || !piece) throw new Error('no layout');
        // Grab the mean of the outline's vertices: inside the piece for every topology
        // (a triangular piece does not cover the centre of its bounding cell).
        const points = [...String(tile.attributes['path']).matchAll(/[ML](-?[0-9.]+) (-?[0-9.]+)/g)].map((m) => [
          Number(m[1]),
          Number(m[2]),
        ]);
        const cx = points.reduce((sum, [x = 0]) => sum + x, 0) / points.length;
        const cy = points.reduce((sum, [, y = 0]) => sum + y, 0) / points.length;
        const scale = stage.width / area.width;
        return {
          index,
          n: tile.home + 1,
          from: { x: piece.x + (cx - rendered.x) * scale, y: piece.y + (cy - rendered.y) * scale },
          to: { x: stage.x + (cx - area.x) * scale, y: stage.y + (cy - area.y) * scale },
        };
      });
      await drag(page, points.from, points.to);
      const placed = await page.evaluate(
        (i) => (window.puzzlePlayer?.exportState().board as { readonly pieces: readonly { placed: boolean }[] }).pieces[i]?.placed,
        points.index,
      );
      expect(await moves(page)).toBe(1);
      expect(placed).toBe(true);
      await expect(page.locator('.image-puzzle-player [aria-live]')).toHaveText(`Piece ${points.n} placed.`);
    });
  }

  test('jigsaw: keyboard moves and drops a piece; zoom enlarges the stage', async ({ page, isMobile }) => {
    test.skip(isMobile, 'hardware keyboard path is covered by the desktop projects');
    await openPlain(page, 'jigsaw');
    const piece = page.locator(`${board} [data-piece]`).first();
    await piece.focus();
    await page.keyboard.press('Shift+ArrowLeft');
    await page.keyboard.press('Enter');
    expect(await moves(page)).toBe(1);
    const viewport = await page.locator('.image-puzzle-player .viewport').boundingBox();
    await page.locator('.image-puzzle-player [data-control="zoom-in"]').click();
    const stage = await page.locator('.image-puzzle-player .stage').boundingBox();
    expect(stage?.width).toBeGreaterThan((viewport?.width ?? 0) * 1.4);
  });

  test('hint highlights a tile and is counted', async ({ page }) => {
    await openPlain(page, 'rotation');
    await page.locator('.image-puzzle-player [data-control="hint"]').click();
    await expect(page.locator(`${board} .is-hint`)).toHaveCount(1);
    await expect(page.locator('.image-puzzle-player [data-status="hints"]')).toHaveText('Hints: 1');
  });

  test('progress persists across a reload (localStorage)', async ({ page }) => {
    await openPlain(page, 'swap');
    await page.evaluate(() => window.puzzlePlayer?.dispatchAction(window.puzzlePlayer.engine.legalActions()[0]));
    await page.waitForTimeout(400); // autosave debounce
    await page.reload();
    await waitReady(page, 'sliding');
    await page.selectOption('#mode', 'swap');
    await waitReady(page, 'swap');
    expect(await moves(page)).toBe(1);
  });

  test('save, restore and destroy from the host page', async ({ page }) => {
    await openPlain(page, 'swap');
    await page.click('#save');
    await page.evaluate(() => window.puzzlePlayer?.dispatchAction(window.puzzlePlayer.engine.legalActions()[0]));
    expect(await moves(page)).toBe(1);
    await page.click('#restore');
    expect(await moves(page)).toBe(0);
    await page.click('#destroy');
    await expect(page.locator('.image-puzzle-player')).toHaveCount(0);
    await page.click('#destroy');
    await expect(page.locator('.image-puzzle-player')).toHaveCount(1);
  });

  test('switching puzzles quickly keeps only the last one', async ({ page }) => {
    await openPlain(page, 'sliding');
    await page.evaluate(async () => {
      const player = window.puzzlePlayer;
      if (!player) return;
      await Promise.all([player.load('assets/jigsaw.json'), player.load('assets/strips.json')]);
    });
    await waitReady(page, 'strips');
    await expect(page.locator('.image-puzzle-player .piece')).toHaveCount(0);
  });

  test('Italian labels and reduced motion', async ({ page }) => {
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await openPlain(page, 'swap');
    await page.selectOption('#locale', 'it');
    await expect(page.locator('.image-puzzle-player [data-control="reset"]')).toHaveText('Ricomincia');
    const duration = await page
      .locator(`${board} button.tile`)
      .first()
      .evaluate((element) => getComputedStyle(element).transitionDuration);
    expect(duration.split(',').every((value) => parseFloat(value) === 0)).toBe(true);
  });
});
