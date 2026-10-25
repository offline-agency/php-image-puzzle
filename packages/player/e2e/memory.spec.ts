import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';
import { moves, openPlain, waitReady } from './support.js';

const board = '.image-puzzle-player .memory';

interface MemoryState {
  cards: string[];
  matched: string[];
  revealed: string[];
}

const state = (page: Page): Promise<MemoryState> => page.evaluate(() => window.puzzlePlayer?.exportState().board as unknown as MemoryState);

/** Board position of a card. */
async function positionOf(page: Page, card: string): Promise<number> {
  return (await state(page)).cards.indexOf(card);
}

test.describe('memory view', () => {
  test('face-down cards expose neither picture nor label', async ({ page }) => {
    await openPlain(page, 'memory-labels');
    const cards = page.locator(`${board} button.card`);
    await expect(cards).toHaveCount(8);
    for (const card of await cards.all()) {
      await expect(card).toHaveAttribute('data-state', 'down');
      await expect(card).toHaveAttribute('aria-label', /^Card \d+, face down$/);
      expect(await card.evaluate((node) => (node as HTMLElement).style.backgroundImage)).toBe('');
      await expect(card).toHaveText('');
    }
  });

  test('keyboard only: a pair matches, a mismatch is shown then turned face down on screen', async ({ page, isMobile }) => {
    test.skip(isMobile, 'hardware keyboard path is covered by the desktop projects');
    await page.clock.install();
    await openPlain(page, 'memory');
    const a = await positionOf(page, 'p0-a');
    const b = await positionOf(page, 'p0-b');
    const other = await positionOf(page, 'p1-a');
    const card = (position: number) => page.locator(`${board} button.card[data-position="${position}"]`);

    await card(a).focus();
    await page.keyboard.press('Enter');
    await expect(card(a)).toHaveAttribute('aria-label', /^Card \d+: picture 1$/);
    await card(b).focus();
    await page.keyboard.press(' ');
    await expect(card(b)).toHaveAttribute('data-state', 'matched');
    await expect(page.locator('.image-puzzle-player [aria-live]')).toHaveText(/Match!$/);

    await card(other).focus();
    await page.keyboard.press('Enter');
    const third = (await state(page)).cards.find((id) => !id.startsWith('p0') && !id.startsWith('p1')) ?? '';
    await card(await positionOf(page, third)).focus();
    await page.keyboard.press('Enter');
    await expect(page.locator('.image-puzzle-player [aria-live]')).toHaveText(/No match\.$/);
    await expect(card(other)).toHaveAttribute('data-state', 'up');
    // The delay only changes the screen: the state keeps both cards face up.
    await page.clock.runFor(1100);
    await expect(card(other)).toHaveAttribute('data-state', 'down');
    expect((await state(page)).revealed).toHaveLength(2);
    expect(await moves(page)).toBe(4);
    // No undo in memory.
    await expect(page.locator('.image-puzzle-player [data-control="undo"]')).toBeHidden();
  });

  test('pointer: tapping flips, matched cards cannot be flipped again', async ({ page, isMobile }) => {
    await openPlain(page, 'memory');
    const tap = async (position: number): Promise<void> => {
      const card = page.locator(`${board} button.card[data-position="${position}"]`);
      if (isMobile) await card.tap();
      else await card.click();
    };
    await tap(await positionOf(page, 'p2-a'));
    await tap(await positionOf(page, 'p2-b'));
    expect((await state(page)).matched).toEqual(['p2-a', 'p2-b']);
    await tap(await positionOf(page, 'p2-a'));
    expect(await moves(page)).toBe(2);
  });

  test('text labels show on the second card of each pair', async ({ page }) => {
    await openPlain(page, 'memory-labels');
    const position = await positionOf(page, 'p1-b');
    const card = page.locator(`${board} button.card[data-position="${position}"]`);
    await card.click();
    await expect(card).toHaveText('Green');
    await expect(card).toHaveAttribute('aria-label', `Card ${position + 1}: Green`);
  });

  test('a partly matched game with a pending mismatch survives a reload', async ({ page }) => {
    await openPlain(page, 'memory');
    await page.evaluate(() => {
      for (const card of ['p0-a', 'p0-b', 'p1-a', 'p2-a']) window.puzzlePlayer?.dispatchAction({ type: 'flip', card });
    });
    const saved = await state(page);
    expect(saved.revealed).toEqual(['p1-a', 'p2-a']);
    await page.waitForTimeout(400); // autosave debounce
    await page.reload();
    await waitReady(page, 'sliding');
    await page.selectOption('#mode', 'memory');
    await waitReady(page, 'memory');
    expect(await state(page)).toEqual(saved);
    await expect(page.locator(`${board} button.card[data-state="matched"]`)).toHaveCount(2);
  });
});
