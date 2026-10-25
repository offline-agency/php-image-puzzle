#!/bin/sh
# Mutation testing executes deliberately broken copies of src/, including the
# export cleanup code. A mutant once turned a cleanup path into the working
# directory and deleted repository files, so mutants only ever run inside a
# disposable copy of the checkout (vendor included).
set -eu

repo=$(cd "$(dirname "$0")/../.." && pwd)
work=$(mktemp -d "${TMPDIR:-/tmp}/image-puzzle-mutation.XXXXXX")
trap 'rm -rf "$work"' EXIT INT TERM

# Plain tar rather than VCS commands: inside containers a worktree's .git file
# points outside the mount.
cd "$repo"
tar -cf - --exclude=./.git --exclude=./build --exclude=./.phpunit.cache . | tar -xf - -C "$work"

cd "$work"
vendor/bin/pest --mutate --parallel --everything --covered-only --min="${MUTATION_MIN:-60}" "$@"
