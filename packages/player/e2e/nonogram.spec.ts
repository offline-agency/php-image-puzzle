import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';
import { moves, openPlain, waitReady } from './support.js';

const grid = '.image-puzzle-player .nonogram';
const board = (page: Page): Promise<string> => page.evaluate(() => (window.puzzlePlayer?.exportState().board as { cells: string }).cells);

test.describe('nonogram view', () => {
  test('clues are column and row headers of an ARIA grid', async ({ page }) => {
    await openPlain(page, 'nonogram');
    const { rows, cols } = await page.evaluate(() => ({
      rows: window.puzzlePlayer?.engine.definition?.rows ?? 0,
      cols: window.puzzlePlayer?.engine.definition?.cols ?? 0,
    }));
    await expect(page.locator(`${grid}[role="grid"]`)).toHaveCount(1);
    await expect(page.locator(`${grid} [role="columnheader"]`)).toHaveCount(cols);
    await expect(page.locator(`${grid} [role="rowheader"]`)).toHaveCount(rows);
    await expect(page.locator(`${grid} [role="gridcell"] button`)).toHaveCount(rows * cols);
    await expect(page.locator(`${grid} [role="rowheader"]`).first()).toContainText(/Row 1 clue [\d ]+/);
  });

  test('keyboard only: arrows, Enter fills, X crosses, Delete clears, undo', async ({ page, isMobile }) => {
    test.skip(isMobile, 'hardware keyboard path is covered by the desktop projects');
    await openPlain(page, 'nonogram');
    const cols = await page.evaluate(() => window.puzzlePlayer?.engine.definition?.cols ?? 0);
    await page.locator(`${grid} button.cell[tabindex="0"]`).focus();
    await page.keyboard.press('Enter');
    expect((await board(page))[0]).toBe('1');
    await page.keyboard.press('ArrowRight');
    await page.keyboard.press('x');
    expect((await board(page)).slice(0, 2)).toBe('1x');
    await page.keyboard.press('ArrowDown');
    await page.keyboard.press(' ');
    expect((await board(page))[cols + 1]).toBe('1');
    await page.keyboard.press('Delete');
    expect((await board(page))[cols + 1]).toBe('.');
    const focused = page.locator(`${grid} button.cell[tabindex="0"]`);
    await expect(focused).toHaveCount(1);
    await expect(focused).toBeFocused();
    await expect(focused).toHaveAttribute('aria-label', 'Row 2, column 2: empty');
    expect(await moves(page)).toBe(4);
    await page.locator('.image-puzzle-player [data-control="undo"]').click();
    expect((await board(page))[cols + 1]).toBe('1');
  });

  test('pointer: the chosen tool applies, tapping again clears', async ({ page, isMobile }) => {
    await openPlain(page, 'nonogram');
    const cell = page.locator(`${grid} button.cell[data-cell="3"]`);
    const tap = async (): Promise<void> => (isMobile ? cell.tap() : cell.click());
    await tap();
    expect((await board(page))[3]).toBe('1');
    await tap();
    expect((await board(page))[3]).toBe('.');
    const cross = page.locator('.image-puzzle-player [data-tool="crossed"]');
    if (isMobile) await cross.tap();
    else await cross.click();
    await expect(cross).toHaveAttribute('aria-pressed', 'true');
    await tap();
    expect((await board(page))[3]).toBe('x');
    await expect(cell).toHaveAttribute('data-state', 'crossed');
  });

  test('completion reveals the picture and locks the grid; progress survives a reload', async ({ page }) => {
    await openPlain(page, 'nonogram');
    await page.locator(`${grid} button.cell[data-cell="0"]`).dispatchEvent('click', { detail: 1 });
    const saved = await board(page);
    expect(saved[0]).toBe('1');
    await page.waitForTimeout(400); // autosave debounce
    await page.reload();
    await waitReady(page, 'sliding');
    await page.selectOption('#mode', 'nonogram');
    await waitReady(page, 'nonogram');
    expect(await board(page)).toBe(saved);
    await expect(page.locator(`${grid} .nonogram-picture`)).toBeHidden();
    await page.evaluate(() => {
      const player = window.puzzlePlayer;
      for (let i = 0; i < 1000 && player !== null && player.engine.status !== 'complete'; i++) {
        const action = player.engine.hint();
        if (action !== null) player.dispatchAction(action);
      }
    });
    await expect(page.locator(`${grid} .nonogram-picture`)).toBeVisible();
    await expect(page.locator(`${grid} button.cell`).first()).toBeDisabled();
  });
});
