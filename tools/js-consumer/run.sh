#!/bin/sh
# Packs the JavaScript workspaces with `npm pack` and installs the tarballs
# into a fresh consumer project (no workspace links, no repository paths),
# then checks that:
#   - each tarball ships only build output, declarations and the README;
#   - every public export, including the React and Vue adapters, type-checks
#     under strict TypeScript;
#   - importing the entries in Node.js (SSR) neither throws nor registers, and
#     the adapters render their container on the server;
#   - a Vite app built from the tarballs plays in Chromium, Firefox and WebKit
#     through both adapters (skipped with SKIP_BROWSER=1).
#   tools/js-consumer/run.sh
set -eu

repo=$(cd "$(dirname "$0")/../.." && pwd)
work=$(mktemp -d "${TMPDIR:-/tmp}/image-puzzle-js-consumer.XXXXXX")
trap 'rm -rf "$work"' EXIT

cd "$repo"
npm run build
npm pack --workspace packages/engine --workspace packages/player --workspace packages/react --workspace packages/vue \
    --pack-destination "$work" > /dev/null

for tarball in "$work"/*.tgz; do
    tar -tzf "$tarball" > "$work/contents.txt"
    if grep -Ev '^package/(dist/.*|global\.d\.ts|README\.md|LICENSE|package\.json)$' "$work/contents.txt"; then
        echo "unexpected file in $(basename "$tarball")" >&2
        exit 1
    fi
done

mkdir "$work/consumer"
cp "$repo/tools/js-consumer/package.json" "$repo/tools/js-consumer/tsconfig.json" "$repo/tools/js-consumer/consumer.ts" \
    "$repo/tools/js-consumer/react-consumer.tsx" "$repo/tools/js-consumer/vue-consumer.ts" "$repo/tools/js-consumer/ssr.mjs" "$work/consumer/"
version() {
    node -p "require('$repo/package.json').devDependencies['$1']"
}
cd "$work/consumer"
# The adapters' peer dependencies, at the versions the workspace tests.
npm install --no-fund --no-audit --ignore-scripts \
    "$work"/offline-agency-image-puzzle-engine-*.tgz \
    "$work"/offline-agency-image-puzzle-player-*.tgz \
    "$work"/offline-agency-image-puzzle-react-*.tgz \
    "$work"/offline-agency-image-puzzle-vue-*.tgz \
    "react@$(version react)" "react-dom@$(version react-dom)" "vue@$(version vue)" \
    "@types/react@$(version @types/react)" "@types/react-dom@$(version @types/react-dom)" \
    "typescript@$(version typescript)" "vite@$(version vite)"
npx tsc -p tsconfig.json
node ssr.mjs

cp -R "$repo/tools/js-consumer/app" app
mkdir -p app/public
cp -R "$repo/examples/player/assets" app/public/puzzles
npx vite build app --logLevel warn
if [ "${SKIP_BROWSER:-0}" != "1" ]; then
    cd "$repo"
    CONSUMER_DIST="$work/consumer/app/dist" npx playwright test -c tools/js-consumer/e2e/playwright.config.ts
fi
echo "js consumer OK"
