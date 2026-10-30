# CodeIgniter 4 recipe

A small CodeIgniter 4 application that lets an author upload a photo, builds
a puzzle from it, plays it in the browser and keeps validated progress on the
server. The files are in [`integrations/codeigniter4`](../integrations/codeigniter4)
and are verified in a fresh `codeigniter4/appstarter` application by
`tools/codeigniter/run.sh` (also a CI job).

This is a recipe, not a package: you copy the files you need into your own
application and adapt them. CodeIgniter is not a dependency of
`offline-agency/image-puzzle`; the library itself is framework independent.

## Is this library needed?

CodeIgniter already has an image library. `\Config\Services::image()->crop()`
(GD or ImageMagick) is enough when you only need **rectangular tiles** and
your own JavaScript handles everything else. A loop over rows and columns
calling `crop()` and `save()` produces a grid of pieces.

This library is worth adding when you need any of the following:

- Shaped pieces (jigsaw tabs, hexagons, triangles, arcs) with transparent
  edges, padded so that pieces fit together exactly.
- A manifest with each piece's correct position, and a ready player (web
  component) that plays it.
- Game modes other than "rearrange the tiles": sliding, rotation, strips,
  memory, tangram, nonogram, each with a solvable start state.
- Server-side validation of a saved game: `GameState::fromArray()` rejects a
  state for another puzzle, an unreachable board or a corrupt history.
- Decoding limits, EXIF orientation and safe output paths handled for you.

[WHY.md](WHY.md) has the wider comparison.

## Install

```bash
composer require offline-agency/image-puzzle
```

The library needs PHP 8.4+ with ext-gd (CodeIgniter 4.7 itself accepts PHP
8.2+, so the library sets the floor). Then copy the recipe into the application root:

| From `integrations/codeigniter4/` | Purpose |
| --- | --- |
| `app/Config/ImagePuzzle.php` | Directories, upload size and decoding limits, allowed modes |
| `app/Config/Services.php` | `puzzleBuilder()` and `puzzles()` service factories (merge into yours) |
| `app/Config/Routes.php` | Five routes (merge into yours) |
| `app/Libraries/PuzzleService.php` | Builds puzzles, resolves IDs to paths, stores progress |
| `app/Controllers/Puzzles.php` | Upload form, build, play page, progress endpoints |
| `app/Views/puzzles/new.php`, `show.php` | Form and player page |
| `tests/ImagePuzzle/PuzzleFlowTest.php` | Feature tests (`CIUnitTestCase`) |
| `phpstan.neon.dist` | PHPStan level max over these files with `codeigniter/phpstan-codeigniter` |

Copy the player script into the web root (republish it after updating the
library):

```bash
cp vendor/offline-agency/image-puzzle/resources/dist/image-puzzle-player.iife.js public/
```

## How the pieces fit

### Routes

```php
$routes->get('/', 'Puzzles::new');
$routes->post('play', 'Puzzles::create');
$routes->get('play/(:segment)', 'Puzzles::show/$1');
$routes->get('play/(:segment)/progress', 'Puzzles::progress/$1');
$routes->post('play/(:segment)/progress', 'Puzzles::saveProgress/$1');
```

Built puzzles are static files under `public/puzzles/<id>/`. Do not give a
route the same name as a directory under `public/`: `php spark serve` and
the `!-d` condition in CodeIgniter's `.htaccess` serve an existing directory
instead of routing to `index.php`. The smoke test caught this with an
earlier `POST /puzzles` route.

### Upload validation

```php
'photo' => "uploaded[photo]|max_size[photo,{$this->settings->maxUploadKb}]|is_image[photo]|mime_in[photo,image/png,image/jpeg,image/gif,image/webp]",
'kind' => 'required|in_list[' . implode(',', $this->settings->kinds) . ']',
'preset' => 'required|in_list[easy,medium,hard]',
'seed' => 'permit_empty|is_natural|less_than_equal_to[2147483647]',
```

CodeIgniter checks the size, the detected MIME type and, since 4.7, that the
client's file extension matches an image type (a JPEG named `photo.php` is
refused). The library decodes the image again with its own limits, so
validation is not the only line of defence.

### Server-chosen paths

The browser never chooses a path. The upload is moved under
`WRITEPATH/uploads/puzzles` with `getRandomName()`, the puzzle ID is
`'p' . bin2hex(random_bytes(8))`, and `PuzzleService` checks every ID against
`^p[0-9a-f]{16}$` before using it in a path, answering anything else with a
404. The original upload is deleted after the build because the builder keeps
its own upright copy.

### Services and limits

`Config\Services::puzzleBuilder()` creates a `DefinitionBuilder` with
`ResourceLimits` from `Config\ImagePuzzle` (6,000 × 6,000 px and 24 million
pixels by default; the library's own defaults are in
[OPERATIONS.md](OPERATIONS.md)). The controller receives `PuzzleService`
through `Services::puzzles()`, or through its constructor in tests.

### Errors

| Exception | Response |
| --- | --- |
| Validation failure | 422 with the form and CodeIgniter's messages |
| `ResourceLimitExceededException` | 422, "The photo is too large." |
| Any other `ImagePuzzleException` | 422, "The photo could not be turned into a puzzle." |
| Unknown or malformed puzzle ID | `PageNotFoundException` (404) |
| `InvalidDefinitionException`, `JsonException` on saved progress | 422 JSON |

Library messages may contain server paths, so the controller logs them with
`log_message()` and shows its own text. When the build fails, the builder
removes the directory it created, so no half-built puzzle is served.

### Player and progress

`show.php` loads `image-puzzle-player.iife.js`, points
`<image-puzzle-player src>` at `puzzles/<id>/definition.json`, restores the
saved state from `GET play/<id>/progress` (204 when there is none) and posts
`exportState()` back 500 ms after each `puzzle-state-change` event. The server
stores the posted state only after `GameState::fromArray($definition, $state)`
accepts it.

The view sends `csrf_header() => csrf_hash()` with each save. The appstarter
does not enable the CSRF filter. If you enable it with token regeneration
(the default when it is on), the token changes after the first save, so either
set `$regenerate = false` in `Config\Security` or read the new token from the
response.

## Production notes

- The web server user must be able to write `public/puzzles`,
  `writable/uploads/puzzles` and `writable/puzzle-progress`.
- Building runs inside the request. For large photos or many puzzles, build
  in a queue worker or with the CLI (`vendor/bin/image-puzzle batch`, see
  [BATCH.md](BATCH.md)) and keep the same server-chosen IDs.
- Old puzzles are never deleted automatically. Remove `public/puzzles/<id>`
  and `writable/puzzle-progress/<id>.json` together when a puzzle expires.
- The progress files are per puzzle, not per user. Key them by user ID (and
  check ownership) if several people play the same puzzle.

## Verify

```bash
tools/codeigniter/run.sh                 # HEAD, CodeIgniter 4.7.*
tools/codeigniter/run.sh HEAD '4.7.*'    # explicit ref and constraint
```

The script needs network access to Packagist and PHP with gd, intl, mbstring
and curl. It:

1. creates an application with `composer create-project codeigniter4/appstarter`;
2. installs the library from a `git archive` of the ref (what Packagist would
   ship, without development files);
3. copies the recipe in and runs PHPStan level max and the feature tests;
4. starts PHP's built-in server with CodeIgniter's router (as `php spark serve`
   does) and runs `tools/codeigniter/smoke.php`. Over real HTTP, the smoke
   uploads a photo, follows the redirect, loads the player page, the
   definition, every piece image and the player script, saves and restores
   progress, and checks that a foreign state, a non-image, an image with a
   `.php` name and a path in the puzzle ID are refused.
