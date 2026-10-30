import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { MODES, openPlain } from './support.js';

/*
 * Automated WCAG 2.1 A/AA checks with axe (open shadow roots included). This
 * finds a subset of issues only; the manual keyboard/screen-reader matrix in
 * docs/PLAYER_API.md complements it.
 */
const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

test.describe('accessibility', () => {
  for (const mode of MODES) {
    test(`${mode}: no axe violations`, async ({ page }) => {
      await openPlain(page, mode);
      const results = await new AxeBuilder({ page }).include('#puzzle').withTags(TAGS).analyze();
      expect(
        results.violations.map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(', ')}`),
      ).toEqual([]);
    });
  }

  test('Web Component page in dark mode: no axe violations', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'dark' });
    await page.goto('examples/player/element.html');
    await page.waitForFunction(() =>
      [...document.querySelectorAll('image-puzzle-player')].every((element) => element.getAttribute('data-state') === 'ready'),
    );
    const results = await new AxeBuilder({ page }).include('image-puzzle-player').withTags(TAGS).analyze();
    expect(results.violations.map((violation) => violation.id)).toEqual([]);
  });

  test('Web Component page: no axe violations', async ({ page }) => {
    await page.goto('examples/player/element.html');
    await page.waitForFunction(() =>
      [...document.querySelectorAll('image-puzzle-player')].every((element) => element.getAttribute('data-state') === 'ready'),
    );
    const results = await new AxeBuilder({ page }).withTags(TAGS).analyze();
    expect(results.violations.map((violation) => violation.id)).toEqual([]);
  });
});
