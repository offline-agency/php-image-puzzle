#!/bin/sh
# Verifies the CodeIgniter 4 recipe (integrations/codeigniter4) in a fresh
# application: creates it with the official appstarter, installs this
# package from the dist archive of a commit (git archive, like
# tools/consumer/run.sh), overlays the example, then runs PHPStan level max
# over the overlay, its PHPUnit tests, and the upload -> play -> save flow
# over real HTTP with the PHP server and router that `php spark serve` uses.
#   tools/codeigniter/run.sh [ref] [codeigniter-constraint]
# Needs network access to Packagist and PHP with gd, intl, mbstring and curl.
set -eu

ref="${1:-HEAD}"
constraint="${2:-4.7.*}"
repo=$(cd "$(dirname "$0")/../.." && pwd)
work=$(mktemp -d "${TMPDIR:-/tmp}/image-puzzle-ci4.XXXXXX")
server=""
cleanup() {
    if [ -n "$server" ]; then
        kill "$server" 2>/dev/null || true
        wait "$server" 2>/dev/null || true
    fi
    rm -rf "$work"
}
trap cleanup EXIT

git -C "$repo" archive --format=zip --output="$work/dist.zip" "$ref"

composer create-project codeigniter4/appstarter "$work/app" "$constraint" --no-interaction --no-progress
cd "$work/app"
php -r '
    $project = json_decode(file_get_contents("composer.json"), true, 64, JSON_THROW_ON_ERROR);
    $package = json_decode(file_get_contents($argv[1]), true, 64, JSON_THROW_ON_ERROR);
    $package["version"] = "2.99.99";
    $package["dist"] = ["type" => "zip", "url" => "file://" . $argv[2]];
    $project["repositories"] = [["type" => "package", "package" => $package]];
    file_put_contents("composer.json", json_encode($project, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
' "$repo/composer.json" "$work/dist.zip"
composer require offline-agency/image-puzzle:2.99.99 --no-interaction --no-progress
composer require --dev "phpstan/phpstan:^2.1" "codeigniter/phpstan-codeigniter:^1.5 || ^2.0" --no-interaction --no-progress

cp -R "$repo/integrations/codeigniter4/app/." app/
cp -R "$repo/integrations/codeigniter4/tests/." tests/
cp "$repo/integrations/codeigniter4/phpstan.neon.dist" .
cp vendor/offline-agency/image-puzzle/resources/dist/image-puzzle-player.iife.js public/

vendor/bin/phpstan analyse --no-progress --memory-limit=1G
vendor/bin/phpunit --no-coverage tests/ImagePuzzle

port="${CI4_PORT:-8089}"
if php -r 'exit(@fsockopen("127.0.0.1", (int) $argv[1]) === false ? 1 : 0);' "$port"; then
    echo "port $port is already in use; set CI4_PORT to a free port" >&2
    exit 1
fi
printf "CI_ENVIRONMENT = development\napp.baseURL = 'http://127.0.0.1:%s/'\n" "$port" > .env
# What `php spark serve` runs, started directly so this script owns the
# process (spark serve leaves its php -S child running when it is killed).
php -S "127.0.0.1:$port" -t public vendor/codeigniter4/framework/system/rewrite.php > "$work/serve.log" 2>&1 &
server=$!
tries=0
until php -r 'exit(@file_get_contents($argv[1]) === false ? 1 : 0);' "http://127.0.0.1:$port/" 2>/dev/null; do
    tries=$((tries + 1))
    if [ "$tries" -gt 50 ]; then
        cat "$work/serve.log" >&2
        echo "php spark serve did not start" >&2
        exit 1
    fi
    sleep 0.2
done
php "$repo/tools/codeigniter/smoke.php" "http://127.0.0.1:$port" "$work/app/vendor/autoload.php"
