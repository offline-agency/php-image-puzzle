import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import type { Locator, Page, Response } from '@playwright/test';

interface PlayerElement extends HTMLElement {
  snapshot(): { status: string; moves: number };
  dispatchAction(action: unknown): { ok: boolean };
  readonly engine: { legalActions(): unknown[] };
}

interface ConfiguratorElement extends HTMLElement {
  readonly value: Record<string, unknown> | null;
  checkValidity(): boolean;
}

// Seeded by workbench/database/seeders/DatabaseSeeder.php: Acme owns puzzle 1, Globex puzzle 2.
// Every test runs signed in as the Acme author (auth.setup.ts).
async function dashboard(page: Page): Promise<void> {
  await page.goto('admin/acme');
  // Dashboard widgets load lazily: let that request finish before navigating on.
  await page.waitForLoadState('networkidle');
}

function moves(player: Locator): Promise<number> {
  return player.evaluate((node: PlayerElement) => node.snapshot().moves);
}

async function ready(player: Locator): Promise<void> {
  await expect
    .poll(() => player.evaluate((node: PlayerElement) => node.snapshot().status), { timeout: 15_000 })
    .toMatch(/^(idle|running)$/);
}

function saveRequest(page: Page): Promise<Response> {
  return page.waitForResponse(
    (response) =>
      response.request().method() === 'POST' &&
      response.url().endsWith('/update') &&
      (response.request().postData() ?? '').includes('"save"'),
  );
}

async function editAcmePuzzle(page: Page): Promise<Locator> {
  await page.goto('admin/acme/puzzles/1/edit');
  return configuratorOf(page);
}

async function configuratorOf(page: Page): Promise<Locator> {
  const configurator = page.locator('image-puzzle-configurator');
  // The saved source image came through the authorized record route: the crop frame is drawn.
  await expect(configurator.locator('.frame')).toBeVisible({ timeout: 15_000 });
  return configurator;
}

test.describe.configure({ mode: 'serial' });

test('the dashboard widget plays the configured example puzzle', async ({ page }) => {
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await dashboard(page);

  await expect(page.getByText('Example puzzle')).toBeVisible();
  await ready(page.locator('image-puzzle-player'));
  expect(errors).toEqual([]);
});

test('an author configures, saves, reloads, generates, plays and downloads a puzzle', async ({ page }) => {
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await dashboard(page);
  let configurator = await editAcmePuzzle(page);

  await configurator.locator('select[name="kind"]').selectOption('sliding');
  await configurator.locator('input[name="seed"]').fill('11');
  await configurator.locator('input[name="seed"]').blur();
  await page.getByRole('button', { name: 'Save changes' }).click();
  await expect(page.getByText('Saved')).toBeVisible();

  await page.waitForLoadState('networkidle');
  await page.reload();
  configurator = await configuratorOf(page);
  await expect.poll(() => configurator.evaluate((node: ConfiguratorElement) => node.value)).toMatchObject({ kind: 'sliding', seed: 11 });

  await page.getByRole('button', { name: 'Generate puzzle' }).click();
  await expect(page.getByText('The puzzle was generated.')).toBeVisible({ timeout: 30_000 });
  const player = page.locator('image-puzzle-player');
  await ready(player);
  // The session may hold progress from another browser project: compare with it.
  const before = await moves(player);

  const saved = saveRequest(page);
  await player.evaluate((node: PlayerElement) => node.dispatchAction(node.engine.legalActions()[0]));
  expect((await saved).ok()).toBe(true);
  await page.waitForLoadState('networkidle');
  await page.reload();
  await ready(page.locator('image-puzzle-player'));
  await expect.poll(() => moves(page.locator('image-puzzle-player'))).toBe(before + 1);

  const download = page.waitForEvent('download');
  await page.getByRole('button', { name: 'Download' }).click();
  expect((await download).suggestedFilename()).toBe('puzzle-1.zip');
  expect(errors).toEqual([]);
});

test('an invalid configuration blocks the form and is not saved', async ({ page }) => {
  await dashboard(page);
  let configurator = await editAcmePuzzle(page);
  const saved = await configurator.evaluate((node: ConfiguratorElement) => node.value);

  await configurator.locator('select[name="kind"]').selectOption('jigsaw');
  await configurator.locator('input[name="seed"]').fill('3000000000');
  await configurator.locator('input[name="seed"]').blur();
  await expect(configurator.getByRole('alert')).toHaveText('Seed must be a whole number from 0 to 2147483647.');
  // The configurator is form-associated: the browser refuses to submit an invalid form.
  expect(await configurator.evaluate((node: ConfiguratorElement) => node.checkValidity())).toBe(false);
  await page.getByRole('button', { name: 'Save changes' }).click();
  await page.waitForLoadState('networkidle');

  await page.waitForLoadState('networkidle');
  await page.reload();
  configurator = await configuratorOf(page);
  await expect.poll(() => configurator.evaluate((node: ConfiguratorElement) => node.value)).toEqual(saved);
});

test('the configurator and the player follow the panel dark mode', async ({ page }) => {
  await page.addInitScript(() => {
    localStorage.setItem('filament:theme:mode', 'dark');
  });
  await dashboard(page);
  await expect(page.locator('image-puzzle-player')).toHaveAttribute('data-theme', 'dark');

  const configurator = await editAcmePuzzle(page);
  await expect(configurator).toHaveAttribute('data-theme', 'dark');
  await page.evaluate(() => {
    document.documentElement.classList.remove('dark');
  });
  await expect(configurator).toHaveAttribute('data-theme', 'light');
});

test("another team's puzzle is refused in the panel and on every package route", async ({ page }) => {
  await dashboard(page);

  expect((await page.goto('admin/globex/puzzles/2/edit'))?.status()).toBe(404);
  expect((await page.goto('admin/acme/puzzles/2/edit'))?.status()).toBe(404);
  expect((await page.request.get('image-puzzle/records/puzzle.2/source')).status()).toBe(403);
  expect((await page.request.get('image-puzzle/puzzles/puzzle.2/definition.json')).status()).not.toBe(200);
  expect((await page.request.get('image-puzzle/records/puzzle.1/source')).status()).toBe(200);
});

for (const mode of ['light', 'dark'] as const) {
  test(`the configurator and the player have no axe violations in the panel (${mode})`, async ({ page }) => {
    await page.addInitScript((value) => {
      localStorage.setItem('filament:theme:mode', value);
    }, mode);
    await editAcmePuzzle(page);
    await page.getByRole('button', { name: 'Generate puzzle' }).click();
    await expect(page.getByText('The puzzle was generated.')).toBeVisible({ timeout: 30_000 });
    await ready(page.locator('image-puzzle-player'));

    const results = await new AxeBuilder({ page })
      .include('image-puzzle-configurator')
      .include('image-puzzle-player')
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
      .analyze();
    expect(results.violations).toEqual([]);
  });
}
