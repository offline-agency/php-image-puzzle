# Batch builds, cache and storage

Three independent building blocks for producing many puzzles. None of them
needs a queue, a database or cloud storage; each is usable on its own.

| Block | Namespace | Does |
| --- | --- | --- |
| Batch | `ImagePuzzle\Batch` | Builds many items with per-item status, progress, cancellation, fail-fast or continue, bounded concurrency and a resumable checkpoint |
| Cache | `ImagePuzzle\Cache` | Reuses identical builds from a local directory, validated on every hit |
| Storage | `ImagePuzzle\Storage` | Publishes a built puzzle to a filesystem, a stream or any Flysystem 3 store (S3-compatible included) so readers only ever see complete puzzles |

The cache and storage hold generated, immutable assets only. Saved games
(player state) never go there: they belong to the browser or to the host
application's state store (see [LIVEWIRE.md](LIVEWIRE.md)).

## Batch

```php
use ImagePuzzle\Batch\{BatchItem, BatchOptions, BatchRunner, BatchEvent, CancellationToken};
use ImagePuzzle\Game\{AssetOptions, BuildConfiguration, GameKind};

$items = [
    new BatchItem('cat', 'photos/cat.jpg', 'out/cat', new BuildConfiguration(GameKind::Jigsaw, 'easy', [], 7)),
    new BatchItem('dog', 'photos/dog.jpg', 'out/dog', new BuildConfiguration(GameKind::Memory, 'medium'), new AssetOptions('webp', 80)),
];
$token = new CancellationToken('/tmp/batch.cancel');   // touch the file to cancel from anywhere

$result = (new BatchRunner())->run(
    $items,
    new BatchOptions(failFast: false, concurrency: 4, checkpoint: 'out/batch.checkpoint.json'),
    static function (BatchEvent $event): void {
        // batch-start, item-start, item-stage (prepare/build/verify), item-end, batch-end
        printf("%s %d/%d %s %s\n", $event->type, $event->completed, $event->total, $event->item ?? '', $event->status ?? '');
    },
    $token,
);

$result->status;   // complete | partial | failed | canceled
$result->counts(); // ['done' => …, 'resumed' => …, 'failed' => …, 'canceled' => …, 'pending' => …]
```

What the runner guarantees:

- **Output ownership.** Every item writes into its own new or empty
  directory; two items cannot share one. An existing non-empty directory
  fails the item and is never touched.
- **Nothing half-built.** An item that fails or is cancelled has its output
  removed, so a directory holds either a verified puzzle (definition parsed,
  every referenced file present) or nothing.
- **Honest status.** The batch is `complete` only when every item is `done`
  or `resumed`. Otherwise it is `partial` (some items failed, the rest ran),
  `failed` (fail-fast stopped it; the remaining items are `pending`) or
  `canceled`. Item errors are reported as `ShortClassName: message` and never
  thrown out of `run()`; invalid batches (duplicate IDs or outputs) throw.
- **Progress.** `completed`/`total` counts finished items; the total is
  known up front. Stage events (`prepare`, `build`, `verify`) are emitted for
  sequential runs only; worker processes report at item end.
- **Cancellation is cooperative.** The token is checked before each item and
  between stages; nothing is interrupted in the middle of a write. A token
  bound to a file reaches worker processes and other processes. With
  concurrency, running workers are sent SIGTERM and their items are
  reported `canceled` with their outputs cleared.
- **Concurrency** forks up to `concurrency` (1–32) workers with ext-pcntl in
  the CLI SAPI, and runs sequentially otherwise. Results are identical.
- **Checkpoint and resume.** The checkpoint records each item's identity
  (ID, source path **and SHA-256 of the source**, output, build
  configuration, asset options) and engine versions, plus the definition ID
  and file hashes of each finished item. A rerun with the same checkpoint
  marks an item `resumed` only when its identity is unchanged and its output
  still verifies with the recorded hashes. A changed source, configuration
  or engine version, a tampered or extra file, or an item that was started
  but not finished is rebuilt; outputs this batch wrote are cleared first.

### One item at a time (queues)

`BatchRunner::runItem()` builds a single item with the same guarantees and
never throws for item problems. It is the hook for any job queue; the core
library has no queue dependency. The Livewire integration ships a ready
Laravel job:

```php
use Illuminate\Support\Facades\Bus;
use ImagePuzzle\Livewire\Jobs\BuildPuzzle;

Bus::batch(array_map(fn (BatchItem $item) => new BuildPuzzle($item, cacheDir: storage_path('puzzle-cache')), $items))
    ->allowFailures()
    ->dispatch();
```

`BuildPuzzle` fails the job with `BuildPuzzleFailed` when the item fails
(the queue records it and may retry it: the output was cleared), skips the
item when its Bus batch was cancelled or its cancel file exists, and
dispatches `PuzzleBuilt` (item and result) after a verified build. Keep
your own per-item status from those events and failures, as the checkpoint
does for `run()`.

### From the command line

```bash
vendor/bin/image-puzzle batch batch.json --concurrency=4 --checkpoint=batch.checkpoint.json --cache=.puzzle-cache
```

The batch file is versioned JSON; relative paths resolve against its
directory:

```json
{
  "format": "image-puzzle/batch",
  "version": 1,
  "items": [
    {
      "id": "cat",
      "source": "photos/cat.jpg",
      "output": "out/cat",
      "build": {"format": "image-puzzle/build-config", "version": 1, "kind": "jigsaw", "preset": "easy", "seed": 7},
      "assets": {"format": "webp", "quality": 80, "resolutions": ["mobile"], "atlas": "mobile"}
    }
  ]
}
```

`build` is a build configuration exactly as `generate --config` reads it
(also what the configurator and the Filament field save). Progress lines go
to standard error, the summary (or `--json` result) to standard output. The
exit code is 0 only for a complete batch, 1 otherwise. Ctrl+C or creating
the `--cancel-file` cancels cooperatively; an already existing cancel file
is refused so a stale one cannot cancel the next run.

## Cache

```php
use ImagePuzzle\Cache\{AssetCache, CachedBuilder};

$cache = new AssetCache('/var/cache/puzzles', maxBytes: 512 * 1024 * 1024);
$built = (new CachedBuilder($cache))->build('photos/cat.jpg', $config, $assets);
$built->puzzle;  // PuzzleDirectory inside the cache: copy or publish it, never modify it
$built->hit;     // true when served from the cache
```

`BatchRunner` accepts an `AssetCache` and copies hits into each item's
output (`ItemResult::$fromCache`).

**Key.** SHA-256 over: the source file's SHA-256, the normalised build
configuration (kind, preset, custom parameters, seed, crop), the asset
options (format, quality, resolutions, atlas), the definition, preset,
geometry, manifest and configuration format versions, a generator revision,
and the PHP and GD versions. Changing any of them is a miss; nothing has to
be invalidated by hand when the library is upgraded.

**Validation.** Every hit re-checks the entry: the stored key material must
equal the requested one, the payload must contain exactly the recorded
files (no missing, extra or linked files) and every file must match its
SHA-256. An invalid entry is removed and rebuilt.

**Concurrency.** Builds run in a staging directory and are renamed into
place only when complete, so readers never see a partial entry. Producers
of the same key are serialised with an exclusive lock file; the second one
finds the first one's entry. A producer that throws caches nothing.

**Retention.** After each new entry the cache prunes itself to `maxBytes`
(default 1 GiB, `0` disables automatic pruning), removing least recently
used entries first (a hit refreshes an entry) but never the entry just
written. `prune(maxBytes, maxAgeSeconds)` applies a size and/or age bound
on demand, for example from a scheduled task, and removes staging left by
crashed builds after an hour. `invalidate($key)` drops one entry. Deleting
the whole directory is always safe.

## Storage and publication

`OutputTarget` is the storage contract: `write`, `writeStream` (the whole
stream from its start), `exists`, `read`, `delete`, with keys restricted to
relative segment paths (`StorageKey`: no `..`, no leading `/` or `.`, no
backslashes). Implementations:

| Target | For |
| --- | --- |
| `FilesystemTarget($root)` | A local directory; each object is written to a temporary file and renamed into place |
| `MemoryTarget` | Tests and in-process pipelines (`php://temp` streams) |
| `FlysystemTarget($filesystem, $config)` | Any Flysystem 3 filesystem, including S3-compatible storage. Optional: `composer require league/flysystem league/flysystem-aws-s3-v3`; the core never requires it |

`Publisher` publishes a built puzzle so readers only ever see complete
puzzles, also on object stores without rename or transactions:

```php
use ImagePuzzle\Storage\{FlysystemTarget, Publisher, UrlResolver};

$publisher = new Publisher(new FlysystemTarget($s3Filesystem), 'puzzles-site');
$publication = $publisher->publish($puzzleDirectory, 'team-photo');

$publication->generation;    // puzzles-site/generations/<definition id>-<digest>
$publication->referenceKey;  // puzzles-site/puzzles/team-photo.json
$publication->definitionUrl(UrlResolver::prefix('https://cdn.example.com'));
```

1. Every file goes under an immutable generation key named after the
   definition ID and a digest of all file contents.
2. `generation.json` lists every file with its SHA-256 and size.
3. The generation is read back and checked.
4. Only then is the reference `puzzles/<name>.json` written. Readers resolve
   a name through its reference (`Publisher::resolve($name)`), never by
   listing generations.

A failure before step 4 leaves the previous reference untouched.
Republishing identical content reuses the generation when its manifest
matches and otherwise rewrites the same bytes, which repairs an interrupted
upload. Unreferenced generations are garbage: delete them by key prefix,
for example with an object-store lifecycle rule. Generation keys never
change content, so they can be cached forever by a CDN; the small reference
objects should be cached briefly or not at all.

### Storage keys are not URLs

Internal keys stay internal. `UrlResolver` maps a key to the URL a browser
loads:

- `UrlResolver::prefix('https://cdn.example.com/puzzles')` or
  `UrlResolver::prefix('/media')` for public buckets and CDNs (each segment
  is URL-encoded; only `http(s)` and absolute paths are accepted).
- `UrlResolver::using(fn (string $key): string => …)` for anything else,
  such as a host route that checks access and redirects to an expiring
  pre-signed URL.

The player loads `definition.json` and resolves its relative asset paths
next to it, so per-object signed URLs (each with its own signature) do not
work with a plain definition URL: serve signed access through a host route
that authorises the request and streams or redirects each file, as the
Livewire integration's asset route does.
