#!/bin/sh
# Mutation testing executes deliberately broken copies of src/, including the
# export cleanup code. A mutant once turned a cleanup path into the working
# directory and deleted repository files, so mutants only ever run inside a
# disposable copy of the checkout (vendor included).
#
# The default scope is the decision code where a surviving mutant means a real
# gap: grid/index validation, resource limits, the corner mask, the rasterizer,
# output planning and path confinement, and the game rules. Override with
# MUTATION_CLASSES (comma separated) and MUTATION_MIN.
set -eu

repo=$(cd "$(dirname "$0")/../.." && pwd)
work=$(mktemp -d "${TMPDIR:-/tmp}/image-puzzle-mutation.XXXXXX")
trap 'rm -rf "$work"' EXIT INT TERM

classes=${MUTATION_CLASSES:-'ImagePuzzle\Geometry\Grid,ImagePuzzle\ResourceLimits,ImagePuzzle\EdgeStyle\RoundedCornersStyle,ImagePuzzle\Geometry\Rasterizer,ImagePuzzle\Export\OutputPlan,ImagePuzzle\Export\PathGuard,ImagePuzzle\Game'}

# Plain tar rather than VCS commands: inside containers a worktree's .git file
# points outside the mount.
cd "$repo"
tar -cf - --exclude=./.git --exclude=./build --exclude=./.phpunit.cache --exclude=./node_modules . | tar -xf - -C "$work"

cd "$work"
vendor/bin/pest --mutate --parallel --processes="${MUTATION_PROCESSES:-2}" --class="$classes" --min="${MUTATION_MIN:-60}" "$@"
