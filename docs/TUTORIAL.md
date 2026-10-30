# Tutorial: from an uploaded photo to a saved puzzle

This walk-through uses plain PHP so it applies to any framework. It builds a
jigsaw from an uploaded photo, plays it in the browser, stores the player's
progress on the server after validating it, and offers a printable PDF. The
PHP blocks marked as tested run in CI (`tests/Docs/SnippetsTest.php`), with
`photo.jpg` standing in for the upload.

Requirements: PHP 8.4+ with ext-gd, and `composer require offline-agency/image-puzzle`.

## 1. Store the upload under your own name

Never reuse the client's file name or let a request choose a directory. Keep
uploads outside the web root:

```php
$upload = $_FILES['photo'] ?? null;
if (!is_array($upload) || $upload['error'] !== UPLOAD_ERR_OK || $upload['size'] > 20 * 1024 * 1024) {
    http_response_code(422);
    exit('Upload a photo up to 20 MB.');
}
$name = bin2hex(random_bytes(16));                         // server-chosen
$photo = "/var/app/storage/uploads/{$name}.img";
move_uploaded_file($upload['tmp_name'], $photo);
```

The library checks the real type, size and dimensions before it decodes
anything (see [OPERATIONS.md](OPERATIONS.md#limits)), so the extension does
not matter.

## 2. Build the puzzle

A build configuration says which mode, difficulty, seed and crop to use. The
`<image-puzzle-configurator>` element (and the Filament field) produce one;
here it is written inline. Each build goes into a new directory:

<!-- snippet -->
```php
use ImagePuzzle\Exception\ImagePuzzleException;
use ImagePuzzle\Game\BuildConfiguration;
use ImagePuzzle\Game\DefinitionBuilder;

$config = BuildConfiguration::fromJson('{"format":"image-puzzle/build-config","version":1,"kind":"jigsaw","preset":"easy","seed":42}');
$puzzleId = 'p' . bin2hex(random_bytes(8));
$output = "public/puzzles/{$puzzleId}";   // served as /puzzles/<id>/

try {
    $result = (new DefinitionBuilder())->buildConfigured('photo.jpg', $output, $config);
} catch (ImagePuzzleException $e) {
    error_log($e->getMessage());
    exit("The photo could not be turned into a puzzle.\n");
}

file_put_contents('last-puzzle.txt', $puzzleId);
echo "Built {$result->definition->kind->value} puzzle {$result->definition->id}\n";
```

The directory now holds `definition.json`, an upright copy of the photo and
the piece images. Everything in it is immutable: build a new directory to
change the puzzle.

## 3. Play it

Copy the player script from the package once (for example in a deploy step):

```bash
cp vendor/offline-agency/image-puzzle/resources/dist/image-puzzle-player.iife.js public/
```

Then render the element. Progress is restored from the server when there is
some, and saved back after every move:

```html
<script src="/image-puzzle-player.iife.js"></script>
<image-puzzle-player id="game" src="/puzzles/<?= htmlspecialchars($puzzleId) ?>/definition.json"></image-puzzle-player>
<script>
  const game = document.getElementById('game');
  fetch('/progress.php?puzzle=<?= rawurlencode($puzzleId) ?>')
    .then((response) => (response.ok ? response.json() : null))
    .then((state) => { if (state) game.state = state; });
  let timer;
  game.addEventListener('puzzle-state-change', () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
      fetch('/progress.php?puzzle=<?= rawurlencode($puzzleId) ?>', {
        method: 'POST',
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify(game.exportState()),
      });
    }, 500);
  });
  game.addEventListener('puzzle-complete', (event) => console.log('solved in', event.detail.moves, 'moves'));
</script>
```

Players can drag or tap pieces, or use the keyboard (Tab to a piece, arrows
to move it, Enter to drop, Escape to revert); moves and completion are
announced in a live region for screen readers. Without a server, the
`persist` attribute keeps progress in the browser instead.

## 4. Validate and store progress

A saved state comes from the browser, so treat it as untrusted. The PHP
rules check that it belongs to this puzzle, that the board is reachable and
that the history is consistent. Here the browser's request body is simulated
with two moves played by the PHP engine:

<!-- snippet -->
```php
use ImagePuzzle\Exception\InvalidDefinitionException;
use ImagePuzzle\Game\GameDefinition;
use ImagePuzzle\Game\GameEngine;
use ImagePuzzle\Game\GameState;

$puzzleId = (string) file_get_contents('last-puzzle.txt');   // from the route, then looked up in your database
$definition = GameDefinition::fromJson((string) file_get_contents("public/puzzles/{$puzzleId}/definition.json"));

// What the browser would POST: the state after two moves.
$engine = new GameEngine();
$state = $engine->start($definition);
for ($i = 0; $i < 2; $i++) {
    $state = $engine->apply($definition, $state, $definition->rules()->hint($definition, $state->board) ?? $definition->rules()->legalActions($definition, $state->board)[0]);
}
$body = json_encode($state->toArray());

try {
    $saved = GameState::fromArray($definition, json_decode((string) $body, true, 32, JSON_THROW_ON_ERROR));
} catch (InvalidDefinitionException | JsonException $e) {
    http_response_code(422);
    exit("Rejected: {$e->getMessage()}\n");
}
file_put_contents("progress-{$puzzleId}.json", json_encode($saved->toArray()));   // or your database
echo "Stored {$saved->moves} moves", $saved->completed ? ', solved' : '', "\n";
```

Return the stored document from the `GET` handler and the player resumes
where it stopped. Treat a `completed` flag as trustworthy only after this
validation; the browser's `puzzle-complete` event alone proves nothing.

## 5. Offer a printable version

The CLI exports any puzzle directory; for example, a jigsaw built and printed
in two commands:

<!-- snippet -->
```bash
vendor/bin/image-puzzle generate photo.jpg public/puzzles/printable --kind=jigsaw --preset=easy
vendor/bin/image-puzzle pdf public/puzzles/printable printable.pdf --paper=a4
```

In PHP, the same export is `PdfExporter`. A self-contained ZIP that plays
offline is `image-puzzle bundle` or `BundleExporter` (needs ext-zip). See
[EXPORT.md](EXPORT.md).

## Next steps

- Other modes: change `kind` to `sliding`, `swap`, `rotation`, `strips`,
  `memory`, `tangram` or `nonogram` ([GAME_MODES.md](GAME_MODES.md)).
- Let authors choose the crop and difficulty with
  `<image-puzzle-configurator>` ([PLAYER_API.md](PLAYER_API.md#configurator)).
- Laravel: the [Livewire](LIVEWIRE.md) and [Filament](FILAMENT.md)
  integrations do steps 3 and 4 for you, including authorization.
- CodeIgniter 4: [CODEIGNITER.md](CODEIGNITER.md).
- Many puzzles at once, caching and cloud storage: [BATCH.md](BATCH.md).
