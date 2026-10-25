#!/bin/sh
# Packs the JavaScript workspaces with `npm pack` and installs the tarballs
# into a fresh consumer project (no workspace links, no repository paths),
# then checks that:
#   - each tarball ships only build output, declarations and the README;
#   - every public export type-checks under strict TypeScript;
#   - importing the entries in Node.js (SSR) neither throws nor registers.
#   tools/js-consumer/run.sh
set -eu

repo=$(cd "$(dirname "$0")/../.." && pwd)
work=$(mktemp -d "${TMPDIR:-/tmp}/image-puzzle-js-consumer.XXXXXX")
trap 'rm -rf "$work"' EXIT

cd "$repo"
npm run build
npm pack --workspace packages/engine --workspace packages/player --pack-destination "$work" > /dev/null

for tarball in "$work"/*.tgz; do
    tar -tzf "$tarball" > "$work/contents.txt"
    if grep -Ev '^package/(dist/.*|global\.d\.ts|README\.md|LICENSE|package\.json)$' "$work/contents.txt"; then
        echo "unexpected file in $(basename "$tarball")" >&2
        exit 1
    fi
done

mkdir "$work/consumer"
cp "$repo/tools/js-consumer/package.json" "$repo/tools/js-consumer/tsconfig.json" "$repo/tools/js-consumer/consumer.ts" "$repo/tools/js-consumer/ssr.mjs" "$work/consumer/"
typescript=$(node -p "require('$repo/package.json').devDependencies.typescript")
cd "$work/consumer"
npm install --no-fund --no-audit --ignore-scripts \
    "$work"/offline-agency-image-puzzle-engine-*.tgz \
    "$work"/offline-agency-image-puzzle-player-*.tgz \
    "typescript@$typescript"
npx tsc -p tsconfig.json
node ssr.mjs
echo "js consumer OK"
