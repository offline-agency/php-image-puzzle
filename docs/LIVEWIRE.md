# Livewire integration

`offline-agency/image-puzzle-livewire` is a Livewire 4 component that plays
image-puzzle game definitions in a Laravel application. Gameplay runs in the
browser through the shared `<image-puzzle-player>` Web Component (see
[PLAYER_API.md](PLAYER_API.md)); the server resolves and authorizes the
puzzle, serves its definition and images, validates every saved state with the
PHP rules and stores progress.

| Requirement | Tested versions                           |
| ----------- | ----------------------------------------- |
| PHP         | 8.4, 8.5                                  |
| Laravel     | 13.35 (lowest) to the latest 13.x         |
| Livewire    | 4.4.8 (lowest) to the latest 4.x          |
| Core        | `offline-agency/image-puzzle` 2.x         |
| Browsers    | Chromium, Firefox, WebKit (Playwright CI) |

The package source lives in this repository under `integrations/livewire`.
See [Distribution](#distribution) for how it reaches Packagist.

## Install

```bash
composer require offline-agency/image-puzzle-livewire
php artisan vendor:publish --tag=image-puzzle-config   # optional
```

The service provider is auto-discovered. No npm build step is needed: the
package serves the prebuilt player bundle from its own route, with an
immutable cache header tied to the bundle hash.

## Build a definition

Any game definition JSON works. The core `DefinitionBuilder` writes a
self-contained directory (definition, source image and, for jigsaws, piece
images) from an uploaded picture:

```php
use ImagePuzzle\Game\DefinitionBuilder;
use ImagePuzzle\Game\GameKind;

$result = (new DefinitionBuilder())->build(
    sourcePath: $upload->getRealPath(),
    outputDir: storage_path('app/puzzles/autumn'),
    kind: GameKind::Jigsaw,
    preset: 'medium',
    seed: 42,
);
$result->definitionPath(); // storage/app/puzzles/autumn/definition.json
```

The output directory must be new or empty. On failure it is emptied (and
removed if the builder created it).

To build on a queue, dispatch `ImagePuzzle\Livewire\Jobs\BuildPuzzle` with a
`BatchItem` (alone or in `Bus::batch()`). It fails the job when the build
fails, honours a cancelled Bus batch and dispatches `PuzzleBuilt` when the
output is verified. See [BATCH.md](BATCH.md#one-item-at-a-time-queues).

## Register puzzles

The default repository reads `config/image-puzzle.php`:

```php
'puzzles' => [
    'autumn' => storage_path('app/puzzles/autumn/definition.json'),
],
```

Keys match `^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$`. To load puzzles from a
database or a storage disk, bind your own
`ImagePuzzle\Livewire\Contracts\PuzzleRepository` returning a `StoredPuzzle`
(key, parsed `GameDefinition`, directory that holds its assets).

## Render

```blade
<livewire:image-puzzle-player puzzle="autumn" />
<livewire:image-puzzle-player puzzle="autumn" locale="it" :controls="false" />
<livewire:image-puzzle-player puzzle="autumn" :read-only="true" />
<livewire:image-puzzle-player puzzle="autumn" theme-class="dark" />
```

| Property   | Meaning                                                   |
| ---------- | --------------------------------------------------------- |
| `puzzle`   | Repository key. Locked: the browser cannot change it.     |
| `locale`   | Player UI language (`en`, `it`, falls back to `en`).      |
| `controls` | Show the toolbar (new game, undo, redo, hint, ...).       |
| `readOnly` | Play stays local; saves and resets are refused with 403.  |
| `themeClass` | Follow a class-based dark mode on `<html>` (e.g. `dark`). |

The element sits inside `wire:ignore`, so Livewire morphs never touch the
game. Methods callable from the page:

- `savedState()` returns the stored state for this session, or `null`.
- `save(state)` validates and stores a state document (debounced by the
  component script; see `sync_debounce_ms`).
- `resetProgress()` forgets the save and replaces the browser game with a
  fresh one (new `wire:key`).

### Completion event

After a save whose board is solved, the component dispatches
`image-puzzle-completed` with `puzzle`, `definitionId`, `moves`, `elapsedMs`
and `hintsUsed`:

```js
Livewire.on('image-puzzle-completed', (event) => { /* ... */ });
```

or in another component: `#[On('image-puzzle-completed')]`.

## Authorization

Every request (component mount and calls, definition, assets) checks the
`image-puzzle.play` gate with the current user (or `null` for guests), the
puzzle key and the puzzle's subject: the record a repository attached to the
`StoredPuzzle` (`subject`), or `null` for configured puzzles. When the
application has not defined the ability, the package default allows
configured puzzles and requires the `view` policy ability on a subject.
Define it to change that:

```php
Gate::define('image-puzzle.play', fn (?User $user, string $puzzle, ?object $subject = null): bool =>
    $user !== null && ($subject === null || $user->can('view', $subject)));
```

`Puzzles::authorized($key)` resolves and authorizes a key (throwing 404/403);
`Puzzles::playable($key)` returns the `StoredPuzzle` or `null` without
throwing, for pages that render a message instead.

Unknown keys respond 404, denied keys 403. A grant revoked while the page is
open is enforced on the next save. The asset route serves only files the
definition references (source image, piece images), resolved inside the
puzzle directory; anything else is 404.

## Saved progress

States are stored per puzzle and definition id by
`ImagePuzzle\Livewire\Contracts\StateStore`. The default `SessionStateStore`
keeps them in the Laravel session, so progress follows the browser session.
Bind your own store (for example keyed by user id in a table) to keep
progress across devices:

```php
$this->app->bind(StateStore::class, DatabaseStateStore::class);
```

A save is first restored into the browser game when it is ready (a failed or
superseded restore request is retried twice before the fresh game is kept),
then every move is sent after `sync_debounce_ms` (default 2000 ms). Completion, hiding the
page and leaving it through `wire:navigate` send the pending state at once.

### What the server verifies — and what it cannot

Before storing, the server parses the document with `GameState::fromJson`
against the puzzle definition: wrong definition id, unknown or malformed
fields, a board (or undo/redo history entry) that is not a valid arrangement
for the mode, documents above `GameState::MAX_JSON_BYTES` and a `completed`
flag that does not match the board are rejected. "Verified" therefore means *a
valid state of this puzzle whose board is solved*. The server does not replay
the moves, so it cannot prove the player solved it without help; do not use
the event alone for prizes or rankings.

## Configuration

| Key                | Default          | Meaning                               |
| ------------------ | ---------------- | ------------------------------------- |
| `puzzles`          | `[]`             | Default repository: key => JSON path. |
| `route_prefix`     | `image-puzzle`   | Prefix of the package routes.         |
| `middleware`       | `['web']`        | Must include the session.             |
| `sync_debounce_ms` | `2000`           | Delay before a save (minimum 100).    |

Routes: `image-puzzle.script`, `image-puzzle.definition`,
`image-puzzle.asset`.

## Development

```bash
cd integrations/livewire
composer install
composer test       # Pest: component, routes, authorization, validation
composer analyse    # PHPStan max + Larastan + strict rules
composer serve      # workbench demo at http://127.0.0.1:8000
# browser tests (from the repository root, after npm ci):
npx playwright test -c integrations/livewire/playwright.config.ts
```

The bundled script `resources/dist/image-puzzle-player.bundle.js` is a copy
of `packages/player/dist`. After changing the player, run `npm run build &&
npm run sync:bundles`; CI fails when the copy is stale.

## Distribution

The package is developed in this monorepo and installs the core through a
Composer path repository (`../..`). Composer ignores the `repositories` of
dependencies, so consumers resolve the core from Packagist. Publishing needs
repository-admin work that is outside the code change:

1. Create the read-only repository `offline-agency/image-puzzle-livewire`.
2. Mirror `integrations/livewire` into it on every release tag, for example
   with `git subtree split --prefix=integrations/livewire -b split/livewire`
   followed by a push of that branch and the tag.
3. Submit the repository to Packagist and enable its GitHub webhook.

Until that is done, install from this repository with a path or VCS
repository pointing at `integrations/livewire`.
