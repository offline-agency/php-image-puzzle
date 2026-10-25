import { execFileSync } from 'node:child_process';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';
import type { ImagePuzzlePlayerElement } from '../src/index.js';

/*
 * Extracted ZIP bundles (BundleExporter) open offline, over HTTP and from
 * file://, load only their own files and can be solved. tools/e2e-bundle.php
 * builds them from source with PHP; BUNDLE_ZIPS (paths separated by the
 * platform delimiter) tests given archives instead, such as bundles made by
 * the installed CLI in a fresh consumer project.
 */
const root = fileURLToPath(new URL('../../../', import.meta.url));
let indexes: string[] = [];

test.describe.configure({ mode: 'serial' });

test.beforeAll(() => {
  const out = `${root}build/e2e-bundle/${test.info().project.name}`;
  const zips = (process.env['BUNDLE_ZIPS'] ?? '').split(process.platform === 'win32' ? ';' : ':').filter((zip) => zip !== '');
  const output = execFileSync('php', [`${root}tools/e2e-bundle.php`, out, ...zips], { encoding: 'utf8' });
  indexes = output.split('\n').filter((line) => line !== '');
  expect(indexes.length).toBeGreaterThan(0);
});

async function openBundle(page: Page, url: string): Promise<string[]> {
  const errors: string[] = [];
  const requests: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  page.on('console', (message) => {
    if (message.type() === 'error') errors.push(message.text());
  });
  page.on('request', (request) => requests.push(request.url()));
  await page.goto(url);
  await page.waitForFunction(() => document.querySelector('image-puzzle-player')?.getAttribute('data-state') === 'ready');
  await expect(page.locator('image-puzzle-player .board')).toHaveAttribute('data-loading', 'false');
  expect(errors).toEqual([]);
  return requests;
}

async function expectPlayable(page: Page): Promise<void> {
  const pieces = await page.evaluate(() => {
    const element = document.querySelector<ImagePuzzlePlayerElement>('image-puzzle-player');
    const root = element?.shadowRoot;
    if (!element || !root) return null;
    const images = [...root.querySelectorAll<HTMLImageElement>('.piece img')];
    const canvases = [...root.querySelectorAll<HTMLCanvasElement>('.piece canvas')];
    return {
      tiles: element.engine.definition?.tiles.length ?? 0,
      pieces: root.querySelectorAll('.piece').length,
      decoded: images.every((image) => image.complete && image.naturalWidth > 0) && canvases.every((canvas) => canvas.width > 0),
    };
  });
  expect(pieces).not.toBeNull();
  expect(pieces?.pieces).toBe(pieces?.tiles);
  expect(pieces?.decoded).toBe(true);
  const status = await page.evaluate(() => {
    const element = document.querySelector<ImagePuzzlePlayerElement>('image-puzzle-player');
    if (!element) return 'missing';
    for (let i = 0; i < 200 && element.engine.status !== 'complete'; i++) {
      const action = element.hint();
      if (action !== null) element.dispatchAction(action);
    }
    return element.engine.status;
  });
  expect(status).toBe('complete');
}

test('extracted bundles play over HTTP using only their own files', async ({ page, baseURL }) => {
  for (const index of indexes) {
    const url = new URL(index.slice(root.length), baseURL).href;
    const folder = url.slice(0, url.lastIndexOf('/') + 1);
    const requests = await openBundle(page, url);
    expect(requests.filter((request) => !request.startsWith(folder) && !request.startsWith('data:'))).toEqual([]);
    expect(requests).toContain(`${folder}player/image-puzzle-player.iife.js`);
    await expectPlayable(page);
  }
});

test('extracted bundles play from file://', async ({ page }) => {
  for (const index of indexes) {
    const url = pathToFileURL(index).href;
    const folder = url.slice(0, url.lastIndexOf('/') + 1);
    const requests = await openBundle(page, url);
    expect(requests.filter((request) => !request.startsWith(folder) && !request.startsWith('data:'))).toEqual([]);
    await expectPlayable(page);
  }
});
