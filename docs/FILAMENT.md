# Filament integration

`offline-agency/image-puzzle-filament` brings image puzzles to Filament 5
panels:

- **`PuzzlePlayerWidget`**: plays a puzzle on a dashboard or a page.
- **`PuzzleDefinitionField`**: a form field that edits a build configuration
  with the shared `<image-puzzle-configurator>` (mode, difficulty, custom
  parameters, seed, crop over a preview of the uploaded image).
- **The authoring flow**: source image → crop and settings → mode and
  preset → preview → generation (`GeneratePuzzleAction`, during the request
  or queued) → play (`PuzzlePreview`) → export (`DownloadPuzzleAction`, a ZIP).

It builds on the [Livewire integration](LIVEWIRE.md): the player, its routes,
saved progress and server-side state validation are the Livewire package's.
Gameplay and configuration run in the browser through the shared Web
Components (see [PLAYER_API.md](PLAYER_API.md)); generation uses the core
`DefinitionBuilder::buildConfigured()`.

| Requirement | Tested versions                           |
| ----------- | ----------------------------------------- |
| PHP         | 8.4, 8.5 (with `ext-intl`, as Filament)   |
| Laravel     | 13.35 (lowest) to the latest 13.x         |
| Filament    | 5.10.3 (lowest) to the latest 5.x         |
| Livewire    | 4.4.8 (lowest) to the latest 4.x          |
| Browsers    | Chromium, Firefox, WebKit (Playwright CI) |

The ZIP export needs `ext-zip`; without it the download action shows a
notification instead.

## Install

```bash
composer require offline-agency/image-puzzle-filament
php artisan vendor:publish --tag=image-puzzle-filament-config   # optional
```

Both service providers (this package and the Livewire one) are
auto-discovered.

## Store puzzles on a model

A record that holds a puzzle implements
`ImagePuzzle\Filament\Contracts\PuzzleRecord`. For Eloquent models, the
`HasImagePuzzle` trait implements it on five columns:

```php
use ImagePuzzle\Filament\PuzzleColumns;

Schema::create('puzzles', function (Blueprint $table) {
    $table->id();
    $table->foreignId('team_id')->constrained();
    $table->string('name');
    PuzzleColumns::define($table); // puzzle_source, puzzle_config, puzzle_definition, puzzle_status, puzzle_error
    $table->timestamps();
});
```

```php
use ImagePuzzle\Filament\Concerns\HasImagePuzzle;
use ImagePuzzle\Filament\Contracts\PuzzleRecord;

final class Puzzle extends Model implements PuzzleRecord
{
    use HasImagePuzzle;

    protected function casts(): array
    {
        return ['puzzle_config' => 'array'];
    }
}
```

| Column              | Content                                                          |
| ------------------- | ---------------------------------------------------------------- |
| `puzzle_source`     | Path of the uploaded image on the source disk (`public`).        |
| `puzzle_config`     | The saved build configuration (JSON).                            |
| `puzzle_definition` | Generated definition, relative to the generation directory.     |
| `puzzle_status`     | `queued`, `running`, `ready` or `failed`.                        |
| `puzzle_error`      | Why the last generation failed, when the author can fix it.      |

Override the trait's protected methods (`imagePuzzleSourceDisk()`,
`imagePuzzleSourceColumn()`, ...) to use other columns or another **local**
source disk.

Register the model under an alias, and give it a policy:

```php
// config/image-puzzle-filament.php
'models' => ['puzzle' => App\Models\Puzzle::class],
```

A record is then played and served under the key `<alias>.<primary key>`,
for example `puzzle.12` (at most 64 characters). Keys with a registered alias
only ever resolve to records, never to configured puzzles.

## Authoring form

```php
use Filament\Forms\Components\FileUpload;
use Filament\Schemas\Components\Section;
use ImagePuzzle\Filament\Forms\Components\PuzzleDefinitionField;
use ImagePuzzle\Filament\Schemas\Components\PuzzlePreview;

public static function form(Schema $schema): Schema
{
    return $schema->components([
        FileUpload::make('puzzle_source')->image()->disk('public')->directory('puzzle-sources')->live()->required(),
        PuzzleDefinitionField::make('puzzle_config')
            ->sourceField('puzzle_source')    // preview the sibling upload
            ->kinds(['jigsaw', 'swap'])       // optional: modes offered, in order
            ->required(),
        Section::make('Play')->schema([PuzzlePreview::make()])->visibleOn(['edit', 'view']),
    ]);
}
```

`PuzzleDefinitionField`:

- State: the configuration document as an array (cast the column to `array`
  or `json`; a JSON string is decoded when the form is filled). The saved
  value is normalised; an untouched field saves the defaults the author sees.
- Validation: the PHP rules (`BuildConfiguration`) on the server, plus the
  allowed `kinds()`. The browser mirrors the same rules; an invalid
  configuration blocks submission natively and shows the message in the
  configurator, and anything that reaches the server is validated again.
- Preview image: a fresh upload previews through Livewire's signed temporary
  URL, a saved image through the `image-puzzle-filament.source` route, which
  serves only the record's own source to users who may `view` the record.
  `imageUrl()` overrides both.
- `disabled()`, `locale()`, labels, helper text and the field wrapper behave
  as for any Filament field; each instance in a repeater, tab or modal is
  independent. The configurator follows the panel's dark mode.

Saved configuration and play progress are separate: the form stores the
configuration on the record, the player stores progress per session through
the Livewire `StateStore`.

## Generate and download

```php
use ImagePuzzle\Filament\Actions\DownloadPuzzleAction;
use ImagePuzzle\Filament\Actions\GeneratePuzzleAction;

protected function getHeaderActions(): array
{
    return [
        GeneratePuzzleAction::make(),            // ->queued() to use the queue
        DownloadPuzzleAction::make(),
    ];
}
```

`GeneratePuzzleAction` uses the **saved** source and configuration (unsaved
form changes are not used), and requires the `update` policy ability. Every
generation is written to a new immutable directory below
`<directory>/<alias>/<id>/`; only after it succeeds does the record point to
it, and the generation it replaced is removed. A failed build leaves the
previous puzzle playable, stores the author-facing reason on the record (for
example *The crop 50x50 at 100,0 is outside the 120x90 image.*) and shows it
in a notification. Unexpected errors are reported to the exception handler
and shown as a generic message.

With `queue` enabled (or `->queued()`), the action marks the record `queued`
and dispatches `ImagePuzzle\Filament\Jobs\GeneratePuzzle` (one attempt:
generation is deterministic). `PuzzlePreview` polls every two seconds while
the status is `queued` or `running`, then shows the player or the error.

`DownloadPuzzleAction` (`view` ability) streams a ZIP with `definition.json`,
every asset of the generation and `build-config.json`, which regenerates the
same puzzle with `DefinitionBuilder::buildConfigured()`.

## Widget

```php
use ImagePuzzle\Filament\Widgets\PuzzlePlayerWidget;

$panel->widgets([
    PuzzlePlayerWidget::make(['puzzle' => 'autumn', 'heading' => 'Puzzle of the week']),
]);
```

On a record page, `PuzzlePlayerWidget::make()` without `puzzle` plays the
page record's puzzle. The key is locked (the browser cannot change it). An
unknown, ungenerated or unauthorized puzzle renders a message instead of
failing the dashboard. A server-verified completion shows a notification and
is re-dispatched to the page as `image-puzzle-widget-completed`
(`puzzle`, `moves`). Like other Filament widgets it loads lazily.

## Authorization and tenancy

The package routes (definition, assets, record source) run outside the
panel, so the panel's tenant scope does not protect them. Every one of them
authorizes the record itself:

- Playing, the definition and the assets go through the Livewire
  `image-puzzle.play` gate, whose default requires the `view` policy ability
  on the record.
- The source preview requires `view`.
- Generation requires `update`, the download `view`.

**Put the tenant check in the policy**, as in the workbench:

```php
public function view(?User $user, Puzzle $puzzle): bool
{
    return $user !== null && $user->teams()->whereKey($puzzle->team_id)->exists();
}
```

Inside the panel, Filament's tenant scope also hides other tenants' records
(404). There are no unscoped preview or download routes, and record files are
derived on the server from the alias and key, so one record can never read or
overwrite another record's assets.

## Configuration

| Key                | Default                       | Meaning                                         |
| ------------------ | ----------------------------- | ----------------------------------------------- |
| `models`           | `[]`                          | Alias => model class.                           |
| `directory`        | `storage_path('app/image-puzzles')` | Local generation directory (not public). |
| `queue`            | `false`                       | Generate in a queued job.                       |
| `queue_connection` | `null`                        | Connection for the job.                         |
| `queue_name`       | `null`                        | Queue for the job.                              |
| `limits.*`         | library defaults              | `max_input_bytes`, `max_pixels`, `max_pieces`.  |

Route: `image-puzzle-filament.source` (under the Livewire `route_prefix` and
`middleware`). Translations: `en`, `it` (`image-puzzle-filament::messages`,
publish with `--tag=image-puzzle-filament-translations`).

## Development

```bash
cd integrations/filament
composer install
composer test       # Pest: field, resource pages, generation, queue, download, access, widget
composer analyse    # PHPStan max + Larastan + strict rules
composer serve      # workbench panel at http://127.0.0.1:8000/admin
# browser tests (from the repository root, after npm ci):
npx playwright test -c integrations/filament/playwright.config.ts
```

Run `vendor/bin/testbench workbench:build` before `composer serve`: it
publishes the Filament assets, creates the SQLite database and seeds two
teams. Demo logins: `author@example.test` (Acme) and `other@example.test`
(Globex), password `password`. `APP_URL` must match the served origin for
the FileUpload previews.

PHPStan runs at level max with the strict rules, except
`dynamicCallOnStaticMethod`: Larastan reflects Laravel macros and mixins
(Filament's Livewire testing helpers among them) as static methods, so every
ordinary call to them would be reported.

## Distribution

Like the Livewire package, this package is developed in the monorepo and
installs the core and the Livewire package through Composer path
repositories. Publishing it needs the same repository-admin steps, with the
prefix `integrations/filament` and the repository
`offline-agency/image-puzzle-filament` (see
[LIVEWIRE.md](LIVEWIRE.md#distribution)).
