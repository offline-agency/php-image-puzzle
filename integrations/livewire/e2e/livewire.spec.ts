import { expect, test } from '@playwright/test';
import type { Locator, Page, Response } from '@playwright/test';

interface PlayerElement extends HTMLElement {
  snapshot(): { status: string; moves: number; completed: boolean };
  hint(): unknown;
  dispatchAction(action: unknown): { ok: boolean };
  readonly engine: { legalActions(): unknown[] };
}

function player(page: Page, section: string): Locator {
  return page.locator(`#${section}-section image-puzzle-player`);
}

async function ready(element: Locator): Promise<void> {
  await expect.poll(() => element.evaluate((node: PlayerElement) => node.snapshot().status)).toMatch(/^(idle|running)$/);
}

function moves(element: Locator): Promise<number> {
  return element.evaluate((node: PlayerElement) => node.snapshot().moves);
}

function saveRequest(page: Page): Promise<Response> {
  return page.waitForResponse(
    (response) =>
      response.request().method() === 'POST' &&
      response.url().endsWith('/update') &&
      (response.request().postData() ?? '').includes('"save"'),
  );
}

test.beforeEach(async ({ context }) => {
  // A fresh session per test: saves are session scoped.
  await context.clearCookies();
});

test('a move is saved through Livewire and restored after a reload', async ({ page }) => {
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.goto('/');
  const swap = player(page, 'swap');
  await ready(swap);
  await ready(player(page, 'jigsaw'));
  expect(await moves(swap)).toBe(0);

  const saved = saveRequest(page);
  await swap.evaluate((node: PlayerElement) => node.dispatchAction(node.engine.legalActions()[0]));
  expect((await saved).ok()).toBe(true);

  await page.reload();
  await ready(swap);
  await expect.poll(() => moves(swap)).toBe(1);
  expect(errors).toEqual([]);
});

test('leaving through wire:navigate flushes the pending save', async ({ page }) => {
  await page.goto('/');
  const swap = player(page, 'swap');
  await ready(swap);
  const saved = saveRequest(page);
  await swap.evaluate((node: PlayerElement) => {
    node.dispatchAction(node.engine.legalActions()[0]);
    node.dispatchAction(node.engine.legalActions()[0]);
  });
  // Navigate before the debounce fires.
  await page.getByRole('link', { name: 'Other page' }).click();
  expect((await saved).ok()).toBe(true);
  await expect(page.getByRole('heading', { name: 'Another page' })).toBeVisible();

  await page.getByRole('link', { name: 'Back to the puzzles' }).click();
  const restored = player(page, 'swap');
  await ready(restored);
  await expect.poll(() => moves(restored)).toBe(2);
});

test('a failed restore request is retried instead of dropping the saved progress', async ({ page }) => {
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.goto('/');
  const swap = player(page, 'swap');
  await ready(swap);
  const saved = saveRequest(page);
  await swap.evaluate((node: PlayerElement) => node.dispatchAction(node.engine.legalActions()[0]));
  expect((await saved).ok()).toBe(true);

  // Abort the first savedState() request after the reload, as a superseding request would.
  let aborted = 0;
  await page.route('**/update', async (route) => {
    if (aborted === 0 && (route.request().postData() ?? '').includes('"savedState"')) {
      aborted++;
      await route.abort();
      return;
    }
    await route.fallback();
  });
  await page.reload();
  const restored = player(page, 'swap');
  await ready(restored);
  await expect.poll(() => moves(restored)).toBe(1);
  expect(aborted).toBe(1);
  expect(errors).toEqual([]);
});

test('a solved game is verified by the server before the page reports it', async ({ page }) => {
  await page.goto('/');
  const swap = player(page, 'swap');
  await ready(swap);
  await swap.evaluate((node: PlayerElement) => {
    for (let i = 0; i < 500 && !node.snapshot().completed; i++) {
      node.dispatchAction(node.hint() ?? node.engine.legalActions()[0]);
    }
  });
  await expect(page.locator('#status')).toContainText('swap completed in');
  await expect(page.locator('#status')).toContainText('verified by the server');
});

test('a denied puzzle, its definition and its assets are forbidden', async ({ page, request }) => {
  const response = await page.goto('/secret');
  expect(response?.status()).toBe(403);
  expect((await request.get('/image-puzzle/puzzles/secret/definition.json')).status()).toBe(403);
  expect((await request.get('/image-puzzle/puzzles/unknown/definition.json')).status()).toBe(404);
});
