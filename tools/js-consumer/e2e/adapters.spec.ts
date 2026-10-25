import { expect, test } from '@playwright/test';
import type { Locator, Page } from '@playwright/test';

type Framework = 'react' | 'vue';

interface BrowserPlayer {
  readonly engine: { legalActions(): unknown[]; hint(): unknown; readonly status: string };
  dispatchAction(action: unknown): { ok: boolean };
  snapshot(): { moves: number; completed: boolean };
  exportState(): { board: unknown; moves: number };
}

declare global {
  interface Window {
    puzzles: Record<Framework, () => BrowserPlayer | null>;
  }
}

const FRAMEWORKS: readonly Framework[] = ['react', 'vue'];

function section(page: Page, framework: Framework): Locator {
  return page.locator(`section[data-framework="${framework}"]`);
}

async function events(page: Page, framework: Framework): Promise<string> {
  return (await section(page, framework).locator('output').getAttribute('data-events')) ?? '';
}

function play(page: Page, framework: Framework, steps: number): Promise<{ board: unknown; moves: number }> {
  return page.evaluate(
    ([name, count]) => {
      const player = window.puzzles[name]();
      if (player === null) throw new Error(`${name} player not mounted`);
      for (let i = 0; i < count; i++) player.dispatchAction(player.engine.legalActions()[0]);
      return player.exportState();
    },
    [framework, steps] as const,
  );
}

let errors: string[] = [];

test.beforeEach(async ({ page }) => {
  errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.goto('/');
  for (const framework of FRAMEWORKS) {
    await expect.poll(() => events(page, framework)).toBe('ready:swap');
    await expect(section(page, framework).locator('.image-puzzle-player')).toHaveCount(1);
  }
});

test.afterEach(() => {
  expect(errors).toEqual([]);
});

test('both adapters reach the same state through the same moves', async ({ page }) => {
  const react = await play(page, 'react', 3);
  const vue = await play(page, 'vue', 3);
  expect(react.moves).toBe(3);
  expect(vue.moves).toBe(3);
  expect(vue.board).toEqual(react.board);
});

for (const framework of FRAMEWORKS) {
  test(`${framework}: renders the board, switches puzzles, saves and restores, unmounts and completes`, async ({ page }) => {
    const host = section(page, framework);
    const board = host.locator('.image-puzzle-player');
    await expect(board.locator('[part~="board"]')).toBeVisible();

    await play(page, framework, 2);
    await host.getByRole('button', { name: 'Save' }).click();
    await play(page, framework, 1);
    await host.getByRole('button', { name: 'Restore' }).click();
    await expect.poll(() => page.evaluate((name) => window.puzzles[name]()?.snapshot().moves, framework)).toBe(2);

    await host.getByRole('button', { name: 'Switch puzzle' }).click();
    await expect.poll(() => events(page, framework)).toMatch(/ready:jigsaw$/);
    await expect(board.locator('img, [part~="piece"]').first()).toBeVisible();

    await host.getByRole('button', { name: 'Unmount' }).click();
    await expect(board).toHaveCount(0);
    expect(await page.evaluate((name) => window.puzzles[name](), framework)).toBeNull();
    await host.getByRole('button', { name: 'Mount' }).click();
    await expect.poll(() => events(page, framework)).toMatch(/ready:jigsaw ready:jigsaw$/);

    const completed = await page.evaluate((name) => {
      const player = window.puzzles[name]();
      if (player === null) return false;
      for (let i = 0; i < 2000 && !player.snapshot().completed; i++) {
        const hint = player.engine.hint();
        if (hint === null) break;
        player.dispatchAction(hint);
      }
      return player.snapshot().completed;
    }, framework);
    expect(completed).toBe(true);
    await expect.poll(() => events(page, framework)).toMatch(/complete$/);
  });
}
