import { expect, test } from '@playwright/test';
import type { ImagePuzzlePlayerElement } from '../src/index.js';

test.describe('<image-puzzle-player> (examples/player/element.html)', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('examples/player/element.html');
    await page.waitForFunction(() =>
      [...document.querySelectorAll('image-puzzle-player')].every((element) => element.getAttribute('data-state') === 'ready'),
    );
  });

  test('two independent instances, translated per element', async ({ page }) => {
    const kinds = await page.evaluate(() =>
      [...document.querySelectorAll<ImagePuzzlePlayerElement>('image-puzzle-player')].map((element) => element.engine.definition?.kind),
    );
    expect(kinds).toEqual(['sliding', 'jigsaw']);
    await expect(page.locator('#jigsaw [data-control="reset"]')).toHaveText('Ricomincia');
    await expect(page.locator('#sliding [data-control="reset"]')).toHaveText('Reset');
  });

  test('completion bubbles out of the shadow root to the page', async ({ page }) => {
    await page.evaluate(() => {
      const element = document.querySelector<ImagePuzzlePlayerElement>('#jigsaw');
      if (!element) return;
      for (let i = 0; i < 100 && element.engine.status !== 'complete'; i++) {
        const action = element.hint();
        if (action !== null) element.dispatchAction(action);
      }
    });
    await expect(page.locator('#status')).toHaveText(/^jigsaw solved in \d+ moves\.$/);
  });

  test('src attribute changes load the new puzzle; failures become puzzle-error', async ({ page }) => {
    await page.evaluate(() => {
      document.querySelector('#sliding')?.setAttribute('src', 'assets/strips.json');
    });
    await page.waitForFunction(() => document.querySelector<ImagePuzzlePlayerElement>('#sliding')?.engine.definition?.kind === 'strips');
    await page.evaluate(() => {
      document.querySelector('#sliding')?.setAttribute('src', 'assets/missing.json');
    });
    await expect(page.locator('#status')).toHaveText(/^sliding: Fetching .*missing\.json failed with HTTP 404$/);
    await expect(page.locator('#sliding')).toHaveAttribute('data-state', 'error');
    await expect(page.locator('#sliding .message')).toBeVisible();
  });

  test('moving the element keeps its engine and progress', async ({ page }) => {
    const result = await page.evaluate(async () => {
      const element = document.querySelector<ImagePuzzlePlayerElement>('#sliding');
      if (!element) throw new Error('missing');
      const engine = element.engine;
      element.dispatchAction(engine.legalActions()[0]);
      const holder = document.createElement('div');
      document.body.prepend(holder);
      holder.append(element);
      await new Promise((resolve) => setTimeout(resolve, 50));
      return { same: element.engine === engine, moves: element.snapshot().moves, status: element.engine.status };
    });
    expect(result).toEqual({ same: true, moves: 1, status: 'running' });
  });

  test('properties beat attributes and objects are never serialised into attributes', async ({ page }) => {
    const result = await page.evaluate(async () => {
      const response = await fetch('assets/swap.json');
      const element = document.querySelector<ImagePuzzlePlayerElement>('#sliding');
      if (!element) throw new Error('missing');
      element.definition = (await response.json()) as unknown;
      await new Promise((resolve) => setTimeout(resolve, 50));
      return { kind: element.engine.definition?.kind, attributes: element.getAttributeNames() };
    });
    expect(result.kind).toBe('swap');
    expect(result.attributes).not.toContain('definition');
  });
});
