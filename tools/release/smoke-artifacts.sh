#!/bin/sh
# Checks a directory written by build-artifacts.php from the outside, the way
# a consumer receives it (read-only job, never with a write token):
#   - every file listed in release-manifest.json matches its SHA-256;
#   - each Laravel integration archive is installable: composer.json, the
#     sources, views, and for Livewire the prebuilt player bundle;
#   - the four npm tarballs install together in a fresh project without
#     workspace links, all at the release version (lockstep), with a clean
#     dependency tree, and every entry imports in Node.js.
#   tools/release/smoke-artifacts.sh DIR VERSION
set -eu

dir=$(cd "$1" && pwd)
version=$2
repo=$(cd "$(dirname "$0")/../.." && pwd)
work=$(mktemp -d "${TMPDIR:-/tmp}/image-puzzle-artifacts.XXXXXX")
trap 'rm -rf "$work"' EXIT

cd "$dir"
# Manifest checksums, recomputed with the platform tool.
node -e '
const m = require(process.argv[1]);
if (m.version !== process.argv[2]) { console.error(`manifest version ${m.version}`); process.exit(1); }
for (const f of m.files) console.log(`${f.sha256}  ${f.name}`);
' "$dir/release-manifest.json" "$version" > "$work/SHA256SUMS"
if command -v sha256sum > /dev/null; then sha256sum -c --quiet "$work/SHA256SUMS"; else shasum -a 256 -c --quiet "$work/SHA256SUMS"; fi

# Filament reuses the player of the Livewire package it requires.
for entry in livewire:resources/dist/image-puzzle-player.bundle.js filament:resources/views/; do
    name=${entry%%:*}
    archive="image-puzzle-$name-$version.zip"
    unzip -Z1 "$archive" > "$work/$name.txt"
    for required in composer.json src/ "${entry#*:}"; do
        if ! grep -q "^image-puzzle-$name-$version/$required" "$work/$name.txt"; then
            echo "$archive has no $required" >&2
            exit 1
        fi
    done
done

mkdir "$work/consumer"
cd "$work/consumer"
printf '{"name":"artifact-consumer","private":true,"type":"module"}\n' > package.json
dev() {
    node -p "require('$repo/package.json').devDependencies['$1']"
}
npm install --no-fund --no-audit --ignore-scripts \
    "$dir/offline-agency-image-puzzle-engine-$version.tgz" \
    "$dir/offline-agency-image-puzzle-player-$version.tgz" \
    "$dir/offline-agency-image-puzzle-react-$version.tgz" \
    "$dir/offline-agency-image-puzzle-vue-$version.tgz" \
    "react@$(dev react)" "react-dom@$(dev react-dom)" "vue@$(dev vue)" > /dev/null
npm ls --all > /dev/null
node --input-type=module -e '
import { readFileSync } from "node:fs";
for (const name of ["engine", "player", "react", "vue"]) {
    const pkg = `@offline-agency/image-puzzle-${name}`;
    const json = JSON.parse(readFileSync(`node_modules/${pkg}/package.json`, "utf8"));
    if (json.version !== process.argv[1]) throw new Error(`${pkg} is ${json.version}`);
    await import(pkg);
}
console.log(`npm tarballs ${process.argv[1]} install together and import`);
' "$version"
echo "release artifacts $version verified in $dir"
