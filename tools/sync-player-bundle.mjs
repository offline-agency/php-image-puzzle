#!/usr/bin/env node
// Copies the built player into every PHP package that ships it, or with
// --check fails when a copy differs from packages/player/dist:
// - integrations/*/resources/dist: the ES module bundle;
// - resources/dist (core package): the IIFE, used by self-contained ZIP
//   bundles because a plain <script> also runs from file:// pages.
import { copyFileSync, existsSync, mkdirSync, readFileSync, readdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = dirname(dirname(fileURLToPath(import.meta.url)));
const dist = join(root, 'packages/player/dist');
const check = process.argv.includes('--check');

const bundle = 'image-puzzle-player.bundle.js';
const iife = 'image-puzzle-player.iife.js';
const copies = readdirSync(join(root, 'integrations'), { withFileTypes: true })
  .filter((entry) => entry.isDirectory())
  .map((entry) => ({ file: bundle, target: join(root, 'integrations', entry.name, 'resources/dist', bundle) }))
  .filter(({ target }) => existsSync(dirname(target)));
if (copies.length === 0) {
  console.error('no integrations/*/resources/dist directories found');
  process.exit(1);
}
copies.push({ file: iife, target: join(root, 'resources/dist', iife) });

let stale = 0;
for (const { file, target } of copies) {
  const source = join(dist, file);
  if (!existsSync(source)) {
    console.error(`${source} is missing: run npm run build first.`);
    process.exit(1);
  }
  const relative = target.slice(root.length + 1);
  if (existsSync(target) && readFileSync(target).equals(readFileSync(source))) {
    console.log(`up to date: ${relative}`);
  } else if (check) {
    console.error(`stale: ${relative} (run node tools/sync-player-bundle.mjs)`);
    stale++;
  } else {
    mkdirSync(dirname(target), { recursive: true });
    copyFileSync(source, target);
    console.log(`updated: ${relative}`);
  }
}
process.exit(stale === 0 ? 0 : 1);
