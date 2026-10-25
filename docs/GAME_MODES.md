# Game modes, definitions and state

The PHP core generates **game definitions** that browsers (or a server) play
with one shared rules engine. A definition is immutable; the player's progress
lives in a separate **state** document. Both are versioned JSON validated by
[`docs/schemas/game/v1`](schemas/game/v1).

| Kind | Actions | Board (`initial`, `state.board`) | Hint | Notes |
| --- | --- | --- | --- | --- |
| `sliding` | `{"type":"slide","tile":id}` | `{"cells":[id\|null,…]}` row-major, one `null` | no | Tile next to the gap moves into it |
| `swap` | `{"type":"swap","a":id,"b":id}` | `{"cells":[id,…]}` row-major | yes | Completion compares IDs, never pixels |
| `rotation` | `{"type":"rotate","tile":id,"direction":"cw"\|"ccw"}` | `{"orientations":[deg,…]}` per tile | yes | Tiles never move |
| `strips` | `{"type":"move","strip":id,"to":index}` | `{"order":[id,…]}` | yes | Remove, then insert at `to` |
| `jigsaw` | `{"type":"place","piece":id,"x":int,"y":int}` | `{"pieces":[{"x","y","placed"},…]}` per tile | yes | Pieces from an export manifest; any topology |

Every kind also supports undo, redo, reset and shuffle (`capabilities` in the
definition). Players must hide controls whose capability is `false`.
Memory, tangram and nonogram are separate families (see their own section
once shipped); `Modes::rules()` reports a kind that this version does not ship
instead of mapping it to another game.

## Creating definitions

```php
use ImagePuzzle\Game\GameFactory;
use ImagePuzzle\Game\GameKind;
use ImagePuzzle\Game\SourceImage;

$factory = new GameFactory();
$source = SourceImage::fromFile('photo.jpg');            // dimensions + SHA-256

$sliding = $factory->create(GameKind::Sliding, $source, 'photo.jpg', 'easy', seed: 42);
$strips = $factory->create(GameKind::Strips, $source, 'photo.jpg', 'custom', [
    'count' => 6,
    'axis' => 'vertical',
], seed: 7);

file_put_contents('sliding.json', $sliding->toJson());

// Jigsaw: wrap a version 2 manifest produced by the exporter.
$manifest = json_decode(file_get_contents('pieces/manifest.json'), true);
$jigsaw = $factory->jigsawFromManifest($manifest, 'photo.jpg', 'medium', seed: 3, imageBase: 'pieces/');
```

`media.image` (and jigsaw piece images) are relative paths without dot
segments, or `https` URLs, resolved against the definition's own URL. Never
build them from request input.

### Presets (version 1)

Presets fix generation parameters and allowed assistance only; they are not a
measure of solution length.

| Kind | easy | medium | hard | custom keys |
| --- | --- | --- | --- | --- |
| sliding | 3×3, 60 scramble moves | 4×4, 200 | 5×5, 500 | `rows`, `cols` (2–16), `scrambleMoves` |
| swap | 3×3 | 4×4 | 6×6 | `rows`, `cols` (1–16, ≥ 2 tiles) |
| rotation | 3×3, 0/180° | 4×4, quarter turns | 6×6, quarter turns | `rows`, `cols`, `orientations` |
| strips | 4 horizontal | 8 horizontal | 14 vertical | `count` (2–128), `axis` |
| jigsaw | 3×4, snap 25 % of the smallest cell | 6×8, 15 % | 10×12, 8 % | `snap` argument |

All custom presets also accept `assistance` (`preview`, `numbers`,
`highlightCorrect` booleans). The full table is exported by
`Presets::export()` and in `conformance/presets.json`.

### Geometry

Grid modes play on equal cells. Pixels that do not divide evenly are cropped
symmetrically (the extra pixel goes right/bottom) and recorded in
`board.crop`; each tile's `rect` is its cell in source pixels. Rotation with
90/270° additionally crops to square cells. Jigsaw tiles keep the manifest's
logical cell (`rect`), solved image box (`rendered`), file (`image`) and
outline (`path`); the browser draws exactly this geometry and never
regenerates it.

**Orientation convention:** an orientation is the clockwise rotation in
degrees applied to the tile image when displayed. `0` is upright; `cw` moves
to the next larger allowed angle and `ccw` to the next smaller, wrapping.

**Jigsaw world:** coordinates are source pixels. The board is `board.crop`,
the tray (`rules.tray`) starts more than `rules.snap` pixels to its right and
`rules.area` bounds every piece box. A placement whose box is within `snap`
pixels of the solved box on both axes snaps and locks; otherwise the box must
stay inside the area.

## Randomness

Scrambles depend only on the definition and a seed (0 … 2³²−1), never on
process order. The generator is shared with the TypeScript engine:

1. `state = FNV-1a-32(stream) XOR seed`, with stream
   `image-puzzle/scramble/<kind>`.
2. Each draw is one mulberry32 step on `state`.
3. `int(n) = floor(u · n / 2³²)` for the 32-bit draw `u` (`n ≤ 2²⁰`).
4. `shuffle` is Fisher–Yates from the last index down drawing `int(i + 1)`.

Per kind:

- **sliding**: from the solved board, `scrambleMoves` times pick
  `int(count)` among the tiles next to the gap (ascending cell order),
  excluding the tile moved in the previous step; continue past the requested
  length while the board is solved. Solvable by construction.
- **swap / strips**: shuffle the tile IDs; if the result is solved, exchange
  the first two.
- **rotation**: each tile draws `allowed[int(len)]`; if all are upright, the
  first tile takes `allowed[1]`.
- **jigsaw**: per piece, `x = tray.x + int(tray.width − w + 1)` then
  `y = tray.y + int(tray.height − h + 1)`.

Golden vectors (`conformance/prng.json`) and per-mode transition fixtures
(`conformance/mode-*.json`) are generated from PHP with `composer
conformance`; a test fails when they drift.

## State, history and validation

`GameState` holds the board, `moves` (actions plus undo/redo), `elapsedMs`
(active time measured by the client; it does not advance while paused),
`hintsUsed`, `completed` and up to 100 past/future boards.

`GameState::fromArray($definition, $data)` rejects another puzzle's state,
unknown versions, unknown fields, malformed or unreachable boards (for
sliding: the cell permutation parity must equal the parity of the gap's
distance from its home), a `completed` flag that disagrees with the board, and
corrupt history. Validation happens before anything is replaced, so a failed
import never alters the current game. Limits: definitions ≤ 4 MiB and 4096
tiles, states ≤ 8 MiB, JSON depth 32, IDs `^[a-z][a-z0-9_-]{0,31}$`.

`GameEngine` applies the same transitions on the server (`start`, `apply`,
`undo`, `redo`, `reset`, `shuffle`, `hint`). A client "complete" event is an
interaction signal, not proof: re-validate the submitted state (or replay the
actions) on the server before trusting it.

## Definition identity

`id = "gd1-" + sha256(canonical JSON of every field except $schema and id)`.
Canonical JSON sorts object keys by byte order, keeps list order, has no
whitespace and leaves slashes/Unicode unescaped. The ID therefore changes with
the source digest, configuration, preset version, seed, schema version and
generator algorithm (`generator.algorithm`, bumped whenever identical inputs
would produce different tiles or scrambles). Version 1 is the first format, so
there is nothing to migrate yet; later versions will migrate explicitly or
reject.
