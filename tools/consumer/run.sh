#!/bin/sh
# Installs the package into a fresh consumer project from the dist archive
# that GitHub/Packagist would serve for the current commit (git archive, so
# .gitattributes export-ignore rules apply), without dev dependencies, and
# runs a smoke test through the public API and the installed CLI
# (vendor/bin/image-puzzle). BUNDLE_OUT=<dir> keeps the CLI-made bundle.zip.
#   tools/consumer/run.sh [--prefer-lowest] [ref]
set -eu

lowest=""
if [ "${1:-}" = "--prefer-lowest" ]; then
    lowest="--prefer-lowest"
    shift
fi
ref="${1:-HEAD}"

repo=$(cd "$(dirname "$0")/../.." && pwd)
work=$(mktemp -d "${TMPDIR:-/tmp}/image-puzzle-consumer.XXXXXX")
trap 'rm -rf "$work"' EXIT

git -C "$repo" archive --format=zip --output="$work/dist.zip" "$ref"

# Development-only paths must not ship in the dist archive.
unzip -Z1 "$work/dist.zip" > "$work/contents.txt"
for path in tests/ tools/ docs/quality/ .github/ phpstan.neon.dist pint.json compose.yaml Dockerfile; do
    if grep -q "^$path" "$work/contents.txt"; then
        echo "dist archive contains development path: $path" >&2
        exit 1
    fi
done

mkdir "$work/consumer"
cd "$work/consumer"
php -r '
    $package = json_decode(file_get_contents($argv[1]), true, 64, JSON_THROW_ON_ERROR);
    $package["version"] = "2.99.99";
    $package["dist"] = ["type" => "zip", "url" => "file://" . $argv[2]];
    $project = [
        "name" => "example/consumer",
        "type" => "project",
        "repositories" => [["type" => "package", "package" => $package], ["packagist.org" => false]],
        "require" => ["offline-agency/image-puzzle" => "2.99.99"],
        "config" => ["allow-plugins" => false],
    ];
    file_put_contents("composer.json", json_encode($project, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
' "$repo/composer.json" "$work/dist.zip"

# The package has no runtime Composer dependencies, so the consumer resolves
# offline; --prefer-lowest keeps the job meaningful once it gains some.
composer update --no-dev --no-interaction --no-progress $lowest

php "$repo/tools/consumer/smoke.php"
php "$repo/tools/consumer/cli.php"
