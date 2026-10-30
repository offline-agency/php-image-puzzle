import { execFileSync } from 'node:child_process';
import { existsSync, mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';
import type { ImagePuzzleConfiguratorElement, ImagePuzzlePlayerElement } from '../src/index.js';

/*
 * The whole authoring journey on the plain examples, with the real PHP CLI
 * in between: configure and preview in <image-puzzle-configurator>, build
 * the saved configuration with `image-puzzle generate --config`, play the
 * result in <image-puzzle-player>, persist and restore progress, then
 * export it as a ZIP bundle and a PDF. The Filament flow is covered by
 * integrations/filament/tests (AuthoringTest, GenerationTest).
 */
const root = fileURLToPath(new URL('../../../', import.meta.url));

function cli(args: string[]): string {
  return execFileSync('php', [`${root}bin/image-puzzle`, ...args], { encoding: 'utf8' });
}

function moves(page: Page): Promise<number> {
  return page.evaluate(() => document.querySelector<ImagePuzzlePlayerElement>('#sliding')?.player.snapshot().moves ?? -1);
}

test('configure, preview, generate, play, save and restore, export', async ({ page }) => {
  const work = `${root}build/e2e-journey/${test.info().project.name}`;
  rmSync(work, { recursive: true, force: true });
  mkdirSync(work, { recursive: true });
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));

  // Configure and preview.
  await page.goto('examples/player/configurator.html');
  const configurator = page.locator('#configurator');
  await expect(configurator.locator('.frame')).toBeVisible();
  await configurator.locator('select[name=kind]').selectOption('swap');
  await configurator.locator('select[name=preset]').selectOption('custom');
  await configurator.locator('input[name=rows]').fill('2');
  await configurator.locator('input[name=cols]').fill('3');
  await configurator.locator('input[name=seed]').fill('11');
  await expect(configurator.locator('.summary')).toHaveText('2 × 3 board, tiles of about 213 × 240 px');
  expect(await configurator.evaluate((node: ImagePuzzleConfiguratorElement) => node.checkValidity())).toBe(true);
  await page.getByRole('button', { name: 'Save configuration' }).click();
  const submitted = (await page.locator('#output').textContent()) ?? '';
  expect(submitted).toMatch(/^Submitted: /);
  const config: unknown = JSON.parse(submitted.replace(/^Submitted: /, ''));
  expect(config).toEqual({
    format: 'image-puzzle/build-config',
    version: 1,
    kind: 'swap',
    preset: 'custom',
    custom: { rows: 2, cols: 3 },
    seed: 11,
  });

  // Generate with the PHP CLI from the saved configuration.
  writeFileSync(`${work}/config.json`, JSON.stringify(config));
  const built = JSON.parse(
    cli(['generate', `${root}examples/player/assets/picture.png`, `${work}/puzzle`, `--config=${work}/config.json`, '--json']),
  ) as {
    id: string;
    kind: string;
    tiles: number;
  };
  expect(built).toMatchObject({ kind: 'swap', tiles: 6 });

  // Play the generated definition.
  const definitionUrl = `/build/e2e-journey/${test.info().project.name}/puzzle/definition.json`;
  await page.route('**/examples/player/element.html', async (route) => {
    const response = await route.fetch();
    await route.fulfill({ response, body: (await response.text()).replace('src="assets/sliding.json"', `src="${definitionUrl}"`) });
  });
  const open = async (): Promise<void> => {
    await page.goto('examples/player/element.html');
    await page.waitForFunction(() => document.querySelector('#sliding')?.getAttribute('data-state') === 'ready');
  };
  await open();
  const loaded = await page.evaluate(() => {
    const element = document.querySelector<ImagePuzzlePlayerElement>('#sliding');
    const definition = element?.engine.definition;
    return { id: definition?.id, tiles: definition?.tiles.length, pieces: element?.shadowRoot?.querySelectorAll('.tile').length };
  });
  expect(loaded).toEqual({ id: built.id, tiles: 6, pieces: 6 });
  const played = await page.evaluate(() => {
    const element = document.querySelector<ImagePuzzlePlayerElement>('#sliding');
    const action = element?.hint();
    return action ? element?.dispatchAction(action).ok : false;
  });
  expect(played).toBe(true);
  expect(await moves(page)).toBe(1);

  // Save explicitly, and let the persist attribute save to localStorage.
  const saved = await page.evaluate(() => document.querySelector<ImagePuzzlePlayerElement>('#sliding')?.exportState());
  expect(saved).toMatchObject({ definitionId: built.id });
  await page.waitForFunction((id) => Object.keys(localStorage).some((key) => key.includes(id)), built.id);

  // Restore after a reload, then from the saved document after another move.
  await open();
  await expect.poll(() => moves(page)).toBe(1);
  await page.evaluate(() => {
    const element = document.querySelector<ImagePuzzlePlayerElement>('#sliding');
    const action = element?.hint();
    if (action) element?.dispatchAction(action);
  });
  expect(await moves(page)).toBe(2);
  await page.evaluate((state) => document.querySelector<ImagePuzzlePlayerElement>('#sliding')?.importState(state), saved);
  expect(await moves(page)).toBe(1);
  expect(errors).toEqual([]);

  // Export what was played.
  if (cli(['capabilities', '--json']).includes('"zip": true')) {
    cli(['bundle', `${work}/puzzle`, `${work}/puzzle.zip`]);
    expect(existsSync(`${work}/puzzle.zip`)).toBe(true);
  }
  cli(['pdf', `${work}/puzzle`, `${work}/puzzle.pdf`]);
  expect(existsSync(`${work}/puzzle.pdf`)).toBe(true);
});
