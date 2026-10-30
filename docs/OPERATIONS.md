# Limits, errors and trust

What the library guarantees when it runs inside an application that accepts
images, definitions and saved games from users, and what the application
still has to do.

## Limits

Every image is inspected before it is decoded. `ResourceLimits` rejects it
with `ResourceLimitExceededException` when one bound is exceeded:

| Bound | Default | Constructor argument |
| --- | --- | --- |
| Input file size | 64 MiB | `maxInputBytes` |
| Width and height | 12,000 px each | `maxWidth`, `maxHeight` |
| Decoded pixels | 40,000,000 | `maxPixels` |
| Jigsaw pieces (rows × cols) | 2,500 | `maxPieces` |
| Parallel export workers | 8 | `maxWorkers` |
| Memory budget for all workers together | 1 GiB | `workerMemoryBudget` |

<!-- snippet -->
```php
use ImagePuzzle\Game\DefinitionBuilder;
use ImagePuzzle\Game\GameKind;
use ImagePuzzle\ResourceLimits;
use ImagePuzzle\Exception\ResourceLimitExceededException;

$limits = new ResourceLimits(maxWidth: 4000, maxHeight: 4000, maxPixels: 12_000_000);
try {
    (new DefinitionBuilder(limits: $limits))->build('photo.jpg', 'limited', GameKind::Swap);
} catch (ResourceLimitExceededException $e) {
    error_log($e->getMessage());
    echo "The image is too large.\n"; // what the uploader sees
}
```

GD needs about 5 bytes per pixel to hold a decoded image
(`ResourceLimits::BYTES_PER_PIXEL_ESTIMATE`); `image-puzzle generate
--dry-run` prints the estimate. Keep `memory_limit` above it for the largest
image you accept.

JSON documents have their own bounds, checked before parsing: game
definitions 4 MiB and 4096 tiles, saved states 8 MiB, build configurations
16 KiB, batch files 4 MiB and 10,000 items, JSON depth 32. IDs must match
`^[a-z][a-z0-9_-]{0,31}$`.

## Paths and trust

| Input | Who must check it | How |
| --- | --- | --- |
| An uploaded image | The application | Store it under a name and directory the application chooses; never reuse the client's file name or path. The library decodes it with GD only after checking type, size and dimensions, and never executes or includes it |
| The output directory | The application chooses it | `DefinitionBuilder` requires a new or empty directory and removes what it wrote if the build fails. For `PuzzleGenerator`/`Exporter`, pass an allowed base directory when any part of the path comes from a request: the output must resolve inside it (symlinks included), checked before anything is created |
| Paths inside a definition (`media.image`, `tiles[].image`) | The library | `PuzzleDirectory` resolves every referenced file inside the puzzle directory: no absolute paths, no `..` segments, no symlink that leaves the directory |
| A saved state from a browser | The library, when you call it | `GameState::fromArray($definition, $data)` rejects another puzzle's state, unknown versions and fields, unreachable boards and corrupt history. A client "complete" event is not proof: validate before awarding anything |
| A definition from a user | The library | `GameDefinition::fromJson()` validates schema rules, mode rules and the ID (`gd1-` + SHA-256 of the canonical document), so a tampered definition does not keep its ID |
| Storage keys and URLs | The library | `StorageKey` accepts relative segment paths only; `UrlResolver::prefix()` accepts only `http(s)` URLs and absolute paths ([BATCH.md](BATCH.md#storage-keys-are-not-urls)) |

## Errors

Every exception the library throws on purpose implements
`ImagePuzzle\Exception\ImagePuzzleException`:

| Exception | Meaning | CLI exit code |
| --- | --- | --- |
| `InvalidArgumentException` | A value outside its documented range, an unsafe path, a non-empty output directory | 3 |
| `InvalidDefinitionException` | A definition, state, build configuration or batch file that fails validation | 3 |
| `UnsupportedImageTypeException` | Not an image GD can decode, or a disallowed animated GIF | 3 |
| `ResourceLimitExceededException` | A `ResourceLimits` or JSON bound was exceeded | 5 |
| `CapabilityUnavailableException`, `GdNotAvailableException` | An encoder (WebP, JPEG), ext-zip or ext-gd is missing | 4 |
| `ExportFailedException`, `StorageException` | Writing or publishing failed (disk, permissions, an existing target) | 1 |
| `IllegalActionException` | A well-formed action the rules forbid in the current state | not used by the CLI |

Messages describe the problem in plain language, and many include the
server-side path of the file involved: log them, and show uploaders a
message of your own chosen by exception class. Failures leave no
partial output: see [Ownership](#ownership-of-sources-and-outputs).

## Memory and workers

`Exporter::exportParallel()` and `image-puzzle batch --concurrency` fork
worker processes with ext-pcntl in the CLI SAPI, and run sequentially
otherwise (web requests included), with the same results.

- The parent decodes the source once, before forking; the workers read
  that image through copy-on-write pages and never decode it again, so a
  source file replaced during the export cannot reach some workers only.
  The number of workers is still bounded by `maxWorkers`, by the number of
  pieces and by `workerMemoryBudget` divided by the estimated decode size,
  which is conservative now that the decode is shared.
  `ExportResult::getWorkerMemory()` reports what each worker used; its RSS
  figures include the shared pages, so their sum overstates the physical
  total.
- A worker that crashes, is killed or returns an invalid result fails the
  export (or the batch item); nothing is published from it. Workers never
  return into the caller's code.
- `onPieceBeforeSave` runs inside the workers, so its in-memory side effects
  are lost; `onPieceAfterSave` and `onManifestBuild` run in the parent after
  publication. Callbacks must not exit, fork or keep open handles they
  expect the parent to see.
- Do not fork from a web request: run large exports and batches from a
  queue worker or the CLI ([BATCH.md](BATCH.md)).

## Ownership of sources and outputs

- The library never modifies or deletes the source image. `DefinitionBuilder`
  writes an upright copy (`source.png` or the chosen format) into the output
  directory, and the definition references only that copy.
- An output directory belongs to one build. `DefinitionBuilder` and batch
  items require it to be new or empty; on failure they empty it again (and
  remove it if they created it).
- `Exporter` with the default `in_place` mode stages pieces privately,
  publishes each with an atomic rename and writes the manifest last; a
  failure before publication leaves the previous export untouched, a
  failure during it leaves no manifest. `generation` mode writes each export
  to `generations/<id>/` and atomically switches `current.json`. Old
  generations are not deleted automatically.
- The asset cache and published generations are immutable: copy from them,
  never edit them in place.

## Troubleshooting

| Symptom | Cause and fix |
| --- | --- |
| `The GD extension is required but not loaded.` | Install or enable ext-gd (`apt-get install php8.4-gd`, or enable it in `php.ini`) |
| `cannot encode webp` (exit 4) | The GD build lacks WebP: use PNG or JPEG, or a GD build with libwebp. `image-puzzle capabilities` lists what works |
| `The output directory must be empty` | Choose a new directory per build; the library never overwrites a previous build |
| `ResourceLimitExceededException` for a large photo | Downscale before uploading, or raise the bound you need on `ResourceLimits` and `memory_limit` together |
| Parallel export runs sequentially | ext-pcntl missing, not the CLI SAPI, or the limits allow fewer than two workers |
| The browser player shows no board on `file://` | Definitions are fetched: serve the directory over HTTP, or use a ZIP bundle, which embeds its definition |
