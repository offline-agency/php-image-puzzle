import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import type { Locator, Page } from '@playwright/test';
import type { ImagePuzzleConfiguratorElement } from '../src/index.js';

const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

async function open(page: Page): Promise<Locator> {
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.goto('examples/player/configurator.html');
  const element = page.locator('#configurator');
  // The preview image is decoded: the crop frame is drawn.
  await expect(element.locator('.frame')).toBeVisible();
  expect(errors).toEqual([]);
  return element;
}

function value(element: Locator): Promise<unknown> {
  return element.evaluate((node: ImagePuzzleConfiguratorElement) => node.value);
}

test('loads the saved configuration and previews the board over the picture', async ({ page }) => {
  const element = await open(page);
  expect(await value(element)).toEqual({ format: 'image-puzzle/build-config', version: 1, kind: 'jigsaw', preset: 'easy', seed: 7 });
  await expect(element.locator('.summary')).toHaveText('3 × 4 board, tiles of about 160 × 160 px');
  await expect(element.locator('.grid line')).toHaveCount(4 + 1 + 3 + 1);
});

test('dragging the frame corner and body crops the picture', async ({ page, isMobile }) => {
  test.skip(isMobile, 'Covered by the touch test below');
  const element = await open(page);
  await element.locator('svg').scrollIntoViewIfNeeded();
  const svg = await element.locator('svg').boundingBox();
  if (svg === null) throw new Error('no preview');
  const scale = svg.width / 640;

  // Bottom-right corner handle to the middle: half the picture.
  await page.mouse.move(svg.x + svg.width - 2, svg.y + svg.height - 2);
  await page.mouse.down();
  await page.mouse.move(svg.x + svg.width / 2, svg.y + svg.height / 2, { steps: 8 });
  await page.mouse.up();
  const resized = (await value(element)) as { crop: { x: number; y: number; width: number; height: number } };
  expect(resized.crop.x).toBe(0);
  expect(Math.abs(resized.crop.width - 320)).toBeLessThanOrEqual(2 / scale + 2);
  expect(Math.abs(resized.crop.height - 240)).toBeLessThanOrEqual(2 / scale + 2);

  // Move the frame by its body; it stays inside the picture.
  await page.mouse.move(svg.x + svg.width / 4, svg.y + svg.height / 4);
  await page.mouse.down();
  // Past the picture's bottom-right edge (inside the viewport): the frame is clamped.
  await page.mouse.move(svg.x + svg.width * 0.95, svg.y + svg.height * 0.95, { steps: 8 });
  await page.mouse.up();
  const moved = (await value(element)) as { crop: { x: number; y: number; width: number; height: number } };
  expect(moved.crop.x + moved.crop.width).toBe(640);
  expect(moved.crop.y + moved.crop.height).toBe(480);
  await expect(element.locator('input[name=crop-x]')).toHaveValue(String(moved.crop.x));
});

test('touch drags move the crop frame', async ({ page, isMobile }) => {
  test.skip(!isMobile, 'Touch emulation only on the mobile projects');
  const element = await open(page);
  await element.locator('input[name=crop-width]').fill('320');
  await element.locator('input[name=crop-height]').fill('240');
  const frame = await element.locator('.frame').boundingBox();
  if (frame === null) throw new Error('no frame');
  const start = { x: frame.x + frame.width / 2, y: frame.y + frame.height / 2 };
  await element
    .locator('.frame')
    .dispatchEvent('pointerdown', { pointerId: 7, pointerType: 'touch', button: 0, clientX: start.x, clientY: start.y, isPrimary: true });
  await element.locator('svg').dispatchEvent('pointermove', {
    pointerId: 7,
    pointerType: 'touch',
    clientX: start.x + frame.width,
    clientY: start.y + frame.height,
    isPrimary: true,
  });
  await element.locator('svg').dispatchEvent('pointerup', {
    pointerId: 7,
    pointerType: 'touch',
    clientX: start.x + frame.width,
    clientY: start.y + frame.height,
    isPrimary: true,
  });
  expect(await value(element)).toMatchObject({ crop: { x: 320, y: 240, width: 320, height: 240 } });
});

test('keyboard only: change mode and difficulty, then move the crop', async ({ page, isMobile }) => {
  test.skip(isMobile, 'No hardware keyboard on the mobile projects');
  const element = await open(page);
  await element.locator('select[name=kind]').focus();
  await element.locator('select[name=kind]').selectOption('swap');
  await page.keyboard.press('Tab');
  await expect(element.locator('select[name=preset]')).toBeFocused();
  await element.locator('select[name=preset]').selectOption('custom');
  await element.locator('input[name=rows]').fill('2');
  await element.locator('input[name=cols]').fill('5');
  await element.locator('.frame').focus();
  await page.keyboard.press('Shift+ArrowLeft');
  await page.keyboard.press('ArrowRight');
  expect(await value(element)).toEqual({
    format: 'image-puzzle/build-config',
    version: 1,
    kind: 'swap',
    preset: 'custom',
    custom: { rows: 2, cols: 5 },
    seed: 7,
    crop: { x: 10, y: 0, width: 630, height: 480 },
  });
  await expect(element.locator('.summary')).toHaveText('2 × 5 board, tiles of about 126 × 240 px');
});

test('the form submits the configuration and blocks invalid ones', async ({ page }) => {
  const element = await open(page);
  await page.getByRole('button', { name: 'Save configuration' }).click();
  await expect(page.locator('#output')).toHaveText(
    'Submitted: {"format":"image-puzzle/build-config","version":1,"kind":"jigsaw","preset":"easy","seed":7}',
  );

  await element.locator('input[name=seed]').fill('-1');
  await expect(element.locator('.error')).toHaveText('Seed must be a whole number from 0 to 2147483647.');
  expect(await page.locator('#form').evaluate((form: HTMLFormElement) => form.checkValidity())).toBe(false);
  expect(
    await element.evaluate((node: ImagePuzzleConfiguratorElement) => ({
      valid: node.checkValidity(),
      message: node.validationMessage,
      form: node.form?.id,
      willValidate: node.willValidate,
    })),
  ).toEqual({ valid: false, message: 'Seed must be a whole number from 0 to 2147483647.', form: 'form', willValidate: true });

  await page.getByRole('button', { name: 'Revert' }).click();
  expect(await value(element)).toMatchObject({ seed: 7 });
  expect(await element.evaluate((node: ImagePuzzleConfiguratorElement) => node.checkValidity())).toBe(true);
});

for (const colorScheme of ['light', 'dark'] as const) {
  test(`no axe violations (${colorScheme})`, async ({ page }) => {
    await page.emulateMedia({ colorScheme });
    await open(page);
    const results = await new AxeBuilder({ page }).include('#configurator').withTags(TAGS).analyze();
    expect(results.violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)).toEqual([]);
  });
}
