import { execFileSync } from 'node:child_process';
import { rmSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { expect, test } from '@playwright/test';
import type { ImagePuzzlePlayerElement } from '../src/index.js';

/*
 * The demo site from examples/demo/build.php: one puzzle per mode from the
 * showcase picture, the IIFE shipped in resources/dist and a static index
 * page. Every mode in the menu must load, and a hinted move must be legal
 * where the mode offers hints.
 */
const root = fileURLToPath(new URL('../../../', import.meta.url));
const modes = ['sliding', 'swap', 'rotation', 'strips', 'jigsaw', 'nonogram', 'memory', 'tangram'];

test.describe('demo site', () => {
  let base = '';

  test.beforeAll(() => {
    const info = test.info();
    // One build per worker: tests of this file run in parallel workers.
    const name = `${info.project.name}-${info.workerIndex}`;
    const out = `${root}build/e2e-demo/${name}`;
    rmSync(out, { recursive: true, force: true });
    execFileSync('php', [`${root}examples/demo/build.php`, out, '--title=Demo'], { encoding: 'utf8' });
    base = `/build/e2e-demo/${name}/index.html`;
  });

  test('lists every mode and opens the first by default', async ({ page }) => {
    await page.goto(base);
    await expect(page.getByRole('navigation', { name: 'Game modes' }).getByRole('link')).toHaveCount(modes.length);
    await expect(page.locator('nav a[aria-current="page"]')).toHaveText('Sliding');
    await expect(page.locator('#game')).toHaveAttribute('data-state', 'ready');
  });

  for (const mode of modes) {
    test(`plays ${mode}`, async ({ page }) => {
      const errors: string[] = [];
      page.on('pageerror', (error) => errors.push(error.message));
      await page.goto(`${base}?mode=${mode}`);
      await expect(page.locator('#game')).toHaveAttribute('data-state', 'ready');
      await expect(page.locator('nav a[aria-current="page"]')).toHaveAttribute('data-mode', mode);
      const result = await page.evaluate(async () => {
        const element = document.querySelector<ImagePuzzlePlayerElement>('#game');
        if (element === null) throw new Error('no player on the page');
        const definition = (await (await fetch(element.getAttribute('src') ?? '')).json()) as { kind: string };
        const action = element.player.hint();
        const ok = action === null ? null : element.player.dispatchAction(action).ok;
        return { kind: definition.kind, ok, moves: element.player.snapshot().moves };
      });
      expect(result.kind).toBe(mode);
      if (result.ok !== null) {
        expect(result.ok).toBe(true);
        expect(result.moves).toBe(1);
      }
      expect(errors).toEqual([]);
    });
  }
});
