#!/usr/bin/env node
// Copies the built player bundle into every PHP integration that ships it,
// or with --check fails when a copy differs from packages/player/dist.
import { copyFileSync, existsSync, readFileSync, readdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = dirname(dirname(fileURLToPath(import.meta.url)));
const bundle = 'image-puzzle-player.bundle.js';
const source = join(root, 'packages/player/dist', bundle);
const check = process.argv.includes('--check');

if (!existsSync(source)) {
  console.error(`${source} is missing: run npm run build first.`);
  process.exit(1);
}

const expected = readFileSync(source);
const targets = readdirSync(join(root, 'integrations'), { withFileTypes: true })
  .filter((entry) => entry.isDirectory())
  .map((entry) => join(root, 'integrations', entry.name, 'resources/dist', bundle))
  .filter((target) => existsSync(dirname(target)));

let stale = 0;
for (const target of targets) {
  const relative = target.slice(root.length + 1);
  if (existsSync(target) && readFileSync(target).equals(expected)) {
    console.log(`up to date: ${relative}`);
  } else if (check) {
    console.error(`stale: ${relative} (run node tools/sync-player-bundle.mjs)`);
    stale++;
  } else {
    copyFileSync(source, target);
    console.log(`updated: ${relative}`);
  }
}

if (targets.length === 0) {
  console.error('no integrations/*/resources/dist directories found');
  process.exit(1);
}
process.exit(stale === 0 ? 0 : 1);
