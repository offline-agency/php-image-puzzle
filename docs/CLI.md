# Command line

Installing the package adds `vendor/bin/image-puzzle`:

```bash
composer require offline-agency/image-puzzle
vendor/bin/image-puzzle help
```

It needs PHP 8.4+ with ext-gd. `bundle` also needs ext-zip.

## Commands

| Command | Does |
| --- | --- |
| `generate <image> <output-dir>` | Builds a playable puzzle: `definition.json`, the source image and the piece images |
| `validate <definition.json\|puzzle-dir>` | Parses and validates a definition, including its ID |
| `inspect <puzzle-dir>` | Prints kind, board, tiles and media, and checks every referenced file |
| `reconstruct <puzzle-dir> <out.png>` | Draws the solved board from the puzzle's own files |
| `svg <puzzle-dir> <out.svg>` | Vector outlines and clipped pieces |
| `pdf <puzzle-dir> <out.pdf>` | Printable A4/A3 PDF with cut lines and a reference page |
| `bundle <puzzle-dir> <out.zip>` | Self-contained ZIP that plays offline, also from `file://` |
| `batch <batch.json>` | Builds many puzzles from a batch file with progress, resume, cache and cancellation ([BATCH.md](BATCH.md)) |
| `capabilities` | Modes it can build, image formats GD can decode and encode, optional extensions |
| `help [command]`, `--version` | Help and the installed version |

Every command that writes refuses to replace an existing file, and
`generate` needs a new or empty output directory. `image-puzzle help
<command>` lists all options; the most used ones follow.

## generate

```bash
# A medium jigsaw (the default kind and preset)
vendor/bin/image-puzzle generate photo.jpg public/puzzles/42

# A custom 4x6 jigsaw with WebP pieces, a mobile set and an atlas of that set
vendor/bin/image-puzzle generate photo.jpg out --rows=4 --cols=6 --seed=7 \
    --format=webp --resolutions=mobile --atlas=mobile

# Grid modes: presets, or custom parameters (JSON values are decoded)
vendor/bin/image-puzzle generate photo.jpg out --kind=sliding --preset=hard
vendor/bin/image-puzzle generate photo.jpg out --kind=rotation --rows=3 --cols=3 \
    --param='orientations=[0,90,180,270]'

# A saved build configuration (image-puzzle/build-config v1, as written by
# the configurator and the Filament field), including its crop
vendor/bin/image-puzzle generate photo.jpg out --config=build-config.json

# Check everything without writing: options, image header, limits,
# encoder support, output directory
vendor/bin/image-puzzle generate photo.jpg out --rows=40 --cols=40 --dry-run --json
```

| Option | Values |
| --- | --- |
| `--kind` | `jigsaw` (default), `sliding`, `swap`, `rotation`, `strips` |
| `--preset` | `easy`, `medium` (default), `hard`, `custom` |
| `--rows`, `--cols` | Custom grid; implies `--preset=custom` |
| `--param=KEY=VALUE` | Other custom parameter (see [GAME_MODES.md](GAME_MODES.md)); repeatable |
| `--seed` | 0–4294967295 for grid modes, 0–2147483647 for jigsaw (default 0) |
| `--config` | Build configuration file (at most 16 KiB); excludes the five options above |
| `--format`, `--quality` | `png`, `jpeg`, `webp`; quality as in [EXPORT.md](EXPORT.md#image-formats) |
| `--resolutions` | Comma-separated `thumbnail`, `mobile`, `desktop` or `name:width` (jigsaw only) |
| `--atlas` | `full` or one of the requested resolutions (jigsaw only) |
| `--dry-run` | Print the plan; write nothing |
| `--json` | Machine-readable output |

The dry run reports the source type and size, the build configuration, the
board size, the tile count when the preset or parameters fix it, and an
estimate of the memory needed to decode the source (5 bytes per pixel, the
estimate the library uses for its limits).

## Exports

```bash
vendor/bin/image-puzzle svg out puzzle.svg --image=embed --width-mm=200
vendor/bin/image-puzzle pdf out puzzle.pdf --paper=a3 --width-mm=600   # tiled over sheets
vendor/bin/image-puzzle pdf out puzzle.pdf --dry-run                   # page layout only
vendor/bin/image-puzzle bundle out puzzle.zip --folder=team-photo --title="Team photo"
vendor/bin/image-puzzle reconstruct out board.png
```

See [EXPORT.md](EXPORT.md) for what each export contains, how the PDF is
laid out and how to open a bundle.

## batch

```bash
vendor/bin/image-puzzle batch batch.json                       # sequential
vendor/bin/image-puzzle batch batch.json --concurrency=4 \
    --checkpoint=batch.checkpoint.json --cache=.puzzle-cache   # parallel, resumable, cached
vendor/bin/image-puzzle batch batch.json --fail-fast --json
```

The batch file format, what a checkpoint resumes and how the cache is
bounded are described in [BATCH.md](BATCH.md). The exit code is 0 only when
every item was built or resumed, 1 for a partial, failed or cancelled batch.

## Exit codes

| Code | Meaning | Examples |
| ---: | --- | --- |
| 0 | Success | |
| 1 | Failure while writing | Target already exists, disk error |
| 2 | Usage error | Unknown command or option, missing argument, value outside the allowed list |
| 3 | Invalid input | Unreadable or unsupported image, invalid definition or build configuration, unsafe path in a definition, non-empty output directory |
| 4 | Missing capability | GD cannot encode the requested format, ext-zip missing for `bundle` |
| 5 | Resource limit | Image dimensions or pixels, piece count, configuration size, atlas size |

Errors are printed to standard error as `image-puzzle: <message>`; results go
to standard output. With `--json`, standard output holds only the JSON
document.

## Limits

The CLI uses the library defaults (`ImagePuzzle\ResourceLimits`): 64 MiB
input files, 12000×12000 px and 40 megapixels per image, 2500 pieces. The
limits are checked from the image header, before decoding. To use other
limits, call the library (`new DefinitionBuilder(limits: new ResourceLimits(...))`)
or construct `ImagePuzzle\Cli\Application` with your own `ResourceLimits`.
