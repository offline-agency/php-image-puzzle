# Player API

Browser integrations are built in layers. Each layer delegates to the one
below it; none of them re-implements rules.

| Layer | Package / location | Role |
| --- | --- | --- |
| Definitions and rules | PHP `src/Game`, schemas in `docs/schemas/game/v1` | Generate and validate definitions; optional server-side state validation |
| Headless engine | `@offline-agency/image-puzzle-engine` (`packages/engine`) | Legal actions, transitions, completion, history, timing, import/export. No DOM |
| Browser player | `@offline-agency/image-puzzle-player` (`packages/player`) | DOM renderer and controller: input, focus, labels, persistence, loading |
| Adapters | `<image-puzzle-player>` (same package), Livewire/Filament, Vue, React | Thin wrappers around one player per instance |

Game rules, randomness and state semantics are specified in
[GAME_MODES.md](GAME_MODES.md).

## Engine

```ts
import { createEngine } from '@offline-agency/image-puzzle-engine';

const engine = createEngine({ definition, state, clock, randomSeed, onListenerError });
```

| Member | Behaviour |
| --- | --- |
| `definition` | The loaded, validated, deep-frozen definition, or `null` |
| `status` | `empty`, `idle`, `running`, `paused`, `complete` or `destroyed` |
| `load(definition, state?)` | Validates both documents before replacing the current game. On rejection, the game is unchanged, `error` is emitted and a `PuzzleError` is thrown |
| `start()` / `pause()` | Starts or stops the active-time clock. An accepted action, undo or redo while `idle` or `paused` starts it |
| `reset()` | Restores the definition's initial board and clears moves, time, hints and history |
| `shuffle(seed?)` | Scrambles again with the mode's generator (a random seed when omitted) and clears progress |
| `dispatchAction(action)` | Applies one action. Returns `{ ok: true }` or `{ ok: false, error }`. A rejected action emits nothing and changes nothing |
| `legalActions()` | Every currently legal action (none once complete) |
| `hint()` | The suggested next action, or `null`. A returned hint increments `hintsUsed`. Always `null` when the capability is off |
| `undo()` / `redo()` / `canUndo()` / `canRedo()` | Bounded history (100 boards). Both count as moves |
| `exportState()` | A state document (`state.schema.json`) bound to the definition ID |
| `importState(state)` | Validates first: wrong definition ID, version, unreachable board or malformed history are rejected without a partial change |
| `snapshot()` | `{ status, board, moves, elapsedMs, hintsUsed, progress, completed, canUndo, canRedo }` |
| `subscribe(type, listener)` | Returns an idempotent unsubscribe function. Listeners added or removed during an emit take effect from the next emit |
| `destroy()` | Releases listeners and timers. Any later call throws `PuzzleError('destroyed')` |

Semantics:

- **moves** counts accepted actions plus undo and redo.
- **elapsedMs** is active time only. It does not advance while idle, paused,
  complete, or while the player is suspended or hidden.
- **progress** is a mode-defined fraction in [0, 1]. For grid modes it is the
  share of tiles in their home position (or upright, for rotation). For
  jigsaw it is the share of placed pieces.
- A client-side `complete` is an interaction signal. It does not
  authenticate a result. Servers that award anything must re-validate the
  submitted state (PHP `GameDefinition` and the mode rules can do this).

### Events

Payloads are specified in
[`events.schema.json`](schemas/game/v1/events.schema.json).

| Event | Payload | When |
| --- | --- | --- |
| `ready` | `{ definitionId, kind, state }` | A definition (and optional state) was loaded |
| `move` | `{ action, state }` | An action was accepted |
| `state-change` | `{ reason, status, state }` | After every change. `reason` is one of load, import, action, undo, redo, reset, shuffle, hint, start or pause |
| `progress` | `{ progress, moves, elapsedMs }` | After every `state-change` |
| `complete` | `{ definitionId, moves, elapsedMs, hintsUsed }` | The board became solved |
| `error` | `{ code, message }` | A load, import, fetch, asset or storage operation failed |

Order:

- Accepted action: `move`, `state-change` (action), `progress`, then
  `complete` if the action solved the board.
- Load: `state-change` (load), `progress`, `ready`.
- Any other change: `state-change`, `progress`.

A listener that throws does not stop other listeners or the engine. The error
goes to `onListenerError`; by default it is rethrown asynchronously.

### Error codes

`PuzzleError.code` and `error.detail.code` use the same values:

| Code | Meaning |
| --- | --- |
| `invalid` | A definition or state document failed validation, or no puzzle is loaded |
| `illegal` | An action is not legal in the current position |
| `unsupported` | The mode, version or feature is not supported by this engine |
| `destroyed` | The engine or player was destroyed |
| `load` | Fetching a definition or an asset failed (network, HTTP status, size limit, JSON) |
| `storage` | Browser storage was unavailable or full, or a saved entry was unreadable |

### Persistence

`saveState`, `restoreState`, `clearState` and `autoPersist` work with any
`{ getItem, setItem, removeItem }` storage. `browserStorage()` returns a
probed `localStorage`, or `null` when it is unusable. These functions never
throw. A corrupt, stale or conflicting saved entry is reported, and neither
the current game nor the stored data is changed. Entries are keyed
`image-puzzle:<definitionId>`, so a regenerated puzzle never picks up an old
save. Server persistence is up to the application, which can store
`exportState()` documents.

## Browser player

```js
import { createPlayer } from '@offline-agency/image-puzzle-player';

const player = createPlayer(document.querySelector('#puzzle'), {
  src: '/puzzles/sliding.json', // or definition: object (wins over src)
  locale: 'it',
  persist: true,
});
player.subscribe('complete', ({ moves }) => console.log(moves));
```

`createPlayer(target, options)` appends an element with an open shadow root
to `target`, or renders into a `ShadowRoot` you pass. One live player is
allowed per target; creating a second one throws until `destroy()`.

| Option | Default | Meaning |
| --- | --- | --- |
| `definition` / `src` | — | Initial puzzle. An object definition wins over `src` |
| `state` | — | Saved state for the initial load |
| `baseUrl` | document URL | Base for the assets of an object definition. A fetched definition uses its own URL |
| `locale`, `messages` | `en` | Built-in dictionaries `en` and `it`, plus overrides for any string. Unknown locales fall back to the language, then to English |
| `controls` | `true` | Show the built-in toolbar |
| `persist` | off | `true` or `{ storage, prefix, debounceMs }` |
| `motion` | `auto` | `reduce` or `full` override `prefers-reduced-motion` |
| `pauseWhenHidden` | `true` | Pause the clock while the document is hidden, and resume afterwards |
| `fetch`, `clock`, `randomSeed`, `onListenerError` | platform | Injection points for tests and hosts |

The `Player` interface mirrors the engine: `load(source, { state, baseUrl })`,
`start`, `pause`, `reset`, `shuffle`, `undo`, `redo`, `hint`,
`dispatchAction`, `exportState`, `importState`, `snapshot`, `subscribe` and
`destroy`. It also adds:

- `view`: one of `empty`, `loading`, `error` or `ready`. It is also
  reflected as `data-state` on the host element.
- `setLocale(locale, messages?)`: re-labels everything without changing the
  game.
- `suspend()` / `resume()`: stop timers and flush persistence while
  detached.

Player events are the engine events with identical payloads. The player adds
`error` events with code `load` for fetch and asset failures, and with code
`storage` for persistence failures.

Loading:

- `load()` aborts any fetch still in flight. A load superseded by a newer one
  resolves without effect, so only the last selected puzzle is shown.
- Fetched definitions are limited to 4 MiB, the same limit as the PHP
  `GameDefinition::MAX_JSON_BYTES`. They are requested with
  `credentials: 'same-origin'`.
- Asset paths in a definition are resolved against the definition URL. Only
  `http:`, `https:`, `blob:` and `file:` results are used.
- Labels are always set as text, never parsed as HTML.

`destroy()` aborts pending loads and stops timers, observers and
persistence. Persistence is flushed first. It also releases image requests,
unsubscribes every listener, destroys the engine and removes the host
element.

### Rendering and input

| Mode | Pointer / touch | Keyboard |
| --- | --- | --- |
| sliding | Tap or click a tile next to the gap | Arrows move focus (skipping the gap), Enter or Space slides |
| swap | Drag a tile onto another, or tap two tiles | Enter on two tiles swaps them; Escape cancels |
| rotation | Tap rotates clockwise; Shift+click rotates counter-clockwise | Enter rotates clockwise; Shift+Enter rotates counter-clockwise |
| strips | Drag along the axis, or tap a strip then tap its destination | Enter picks up a strip, arrows choose the slot, Enter drops it, Escape cancels |
| jigsaw | Drag a piece by its PHP-generated outline. Pieces lock within `rules.snap` px of their slot | Tab between unplaced pieces, arrows move (Shift ×5), Enter drops, Escape reverts |

Grid boards use one `<button>` per tile with a roving `tabindex`, so the
board is a single tab stop. Each tile's accessible name gives its number,
row and column, and its orientation. Results are announced in a polite live
region: moves, swaps, rotations, placements, illegal moves and completion.

Jigsaw pieces are the exported PNGs on a world plane in source pixels. Each
piece's outline path is both its hit area and its focus ring, so
interlocking, hexagonal and triangular pieces are grabbed by their real
shape. Board slots are drawn from the same paths. Zoom (1× to 4×) enlarges
the stage inside a scrollable viewport, which provides panning. World
coordinates are independent of zoom, resize and device-pixel ratio.

Piece grouping (moving connected unplaced pieces together) is not
implemented: pieces lock individually onto the board. This keeps every move
a single legal `place` action, which matches the PHP rules.

Controls follow capabilities: undo, redo, reset, shuffle and hint are hidden
when the mode or preset disables them. Preview appears only when the
definition allows it, and zoom only for jigsaw.

### Styling

All markup lives in the shadow root, and host-page CSS does not leak in.
Customise the player through:

- Custom properties: `--ipp-font`, `--ipp-fg`, `--ipp-muted`, `--ipp-bg`,
  `--ipp-surface`, `--ipp-border`, `--ipp-accent`, `--ipp-accent-fg`,
  `--ipp-focus`, `--ipp-correct`, `--ipp-hint`, `--ipp-error`,
  `--ipp-radius`, `--ipp-gap`, `--ipp-tray`, `--ipp-duration`.
- Theme: a dark palette applies when the operating system prefers dark,
  unless the host element has `theme="light"`; `theme="dark"` forces it.
  For a class-based dark mode (Tailwind, Filament), `theme-class="dark"`
  follows that class on `<html>` live and overrides the system preference
  (the element sets `data-theme`). Both palettes pass the automated WCAG AA
  contrast checks.
- Parts: `root`, `toolbar`, `control`, `control-<name>`, `status`,
  `message`, `board`, `board-area`, `viewport`, `tile`, `piece`, `preview`.
- `data-state` on the host (`empty`, `loading`, `error`, `ready`) and
  `data-motion`.

The board keeps left-to-right geometry, because an image is not mirrored. The
toolbar and texts follow the host's `dir`.

## Web Component

```html
<script type="module" src="/vendor/image-puzzle-player.bundle.js"></script>
<image-puzzle-player src="/puzzles/jigsaw.json" locale="it" persist></image-puzzle-player>
```

The tag name is **`image-puzzle-player`**. Ways to register it:

- `import '@offline-agency/image-puzzle-player/define'`.
- The `bundle` or `iife` builds.
- `defineImagePuzzlePlayer(tag?)`, for another tag name.

Registration is idempotent: a second import returns the existing class. In
a server runtime (no `customElements`) nothing is registered and nothing
throws.

| API | Kind | Behaviour |
| --- | --- | --- |
| `src` | attribute and property | Definition URL. Changing it loads the new puzzle |
| `locale` | attribute and property | Updates labels live |
| `persist`, `no-controls`, `motion` | attributes | Read when the player is created |
| `theme`, `theme-class` | attributes | Palette: see Styling |
| `definition` | property only | Definition object. **Wins over `src`**. Set it to `null` to fall back to `src`. Objects are never serialised into attributes |
| `state` | property only | Saved state for the next load. Setting it reloads the current source with that state, then it is cleared |
| `options` | property only | Other `PlayerOptions` (`messages`, `persist`, `fetch`, `clock`, …), read when the player is created |
| `player`, `engine` | read-only | The underlying player and engine |
| `load(source?, options?)`, `start`, `pause`, `reset`, `shuffle`, `undo`, `redo`, `hint`, `dispatchAction`, `exportState`, `importState`, `snapshot` | methods | Delegate to the player |
| `dispose()` | method | Destroys the player. A later connection creates a new one, which restores persisted progress if `persist` is on |

Changes made in the same task (for example, setting `definition` and `state`
together) are batched into one load. Properties assigned before the element
was upgraded are honoured.

Lifecycle:

- A DOM move (disconnected and reconnected in the same task) keeps the same
  engine and game.
- An element that stays detached is *suspended*: the clock pauses and
  persistence is flushed.
- Reconnecting resumes play. Only `dispose()` releases the engine.

Events are dispatched on the element as `CustomEvent`s named `puzzle-ready`,
`puzzle-move`, `puzzle-state-change`, `puzzle-progress`, `puzzle-complete`
and `puzzle-error`. The `detail` is the engine payload. They are
`bubbles: true, composed: true`, so a listener on `document` receives them
even through enclosing shadow roots. The `puzzle-` prefix avoids collisions
with native `error` and `progress` events, which bubble to `window` handlers.

## React and Vue

`@offline-agency/image-puzzle-react` (React 19) and
`@offline-agency/image-puzzle-vue` (Vue 3.5) are thin adapters on the same
player. Each ships a component (`<ImagePuzzlePlayer>`) and a hook or
composable (`useImagePuzzlePlayer`). Install only the adapter for your
framework: it depends on the player, and its framework is its only peer
dependency.

Both adapters share one behaviour, checked by one test contract
(`packages/player/test/adapter-contract.ts`) that also runs against the
binding below:

- `definition` or `src` changes load the new puzzle; a newer source cancels a
  pending load. Definitions are compared by identity.
- `initialState` is restored by the first load after mount only. Later sources
  start fresh games, and a new `initialState` alone never reloads.
- `locale`, `messages`, `theme` and `themeClass` apply live.
- The other `PlayerOptions` are read on mount; remount (a new `key`) to change
  them.
- Event handlers are always the newest ones, without resubscribing.
- Unmount destroys the player, its DOM and its listeners. Server rendering
  outputs the empty container.

Props, events, the imperative handle, Strict Mode and the Web Component route
for each framework are documented in
[packages/react/README.md](../packages/react/README.md) and
[packages/vue/README.md](../packages/vue/README.md).

### `bindPlayer`

The adapters are built on `bindPlayer(target, options, handlers)` from the
player package, which other frameworks can use the same way:

```js
import { bindPlayer } from '@offline-agency/image-puzzle-player';

const binding = bindPlayer(element, { src: '/puzzles/swap.json', locale: 'en' }, () => ({
  complete: ({ moves }) => console.log(moves),
}));
binding.update({ src: '/puzzles/jigsaw.json', locale: 'it' }); // after a prop change
binding.destroy(); // on unmount
```

`handlers` is a function called on every event, so it can return the newest
callbacks. `update()` applies the rules listed above; `binding.player` is the
underlying player.

## Configurator

`<image-puzzle-configurator>` edits a **build configuration**
([schema](schemas/game/v1/build-config.schema.json)): mode, difficulty or
custom parameters, seed, and a crop of the source image in pixels. The PHP
`DefinitionBuilder::buildConfigured()` turns the same document and image into
the same definition, so a saved configuration can be regenerated later.

```html
<form method="post">
  <image-puzzle-configurator name="config" src="/uploads/photo.jpg" kinds="jigsaw swap"></image-puzzle-configurator>
  <button>Save</button>
</form>
```

| API | Kind | Behaviour |
| --- | --- | --- |
| `value` | attribute (JSON) and property | Getter: the valid document or `null`. Setter: document object, JSON string or `null` (defaults). An invalid document shows its error and falls back to defaults |
| `src` | attribute and property | Preview image. Crop coordinates are in its natural (EXIF-upright) pixels |
| `kinds` | attribute and property | Modes offered, space separated, in order |
| `disabled` | attribute and property | Read-only form; pointer and keyboard editing stop |
| `locale` | attribute and property | `en`, `it` |
| `name` | attribute and property | Form field name. The element is form-associated: it submits the JSON and an invalid configuration blocks submission. `form.reset()` restores the `value` attribute |
| `theme`, `theme-class` | attributes | Palette, as for the player |
| `draft` | read-only | The form contents as a document even while invalid (`custom` only with the custom preset, an empty number as `null`): send it when the server should report the errors |
| `valid`, `error`, `configurator` | read-only | State and the underlying controller |
| `form`, `validity`, `validationMessage`, `willValidate`, `checkValidity()`, `reportValidity()` | form-control API | As on native inputs (through `ElementInternals`) |
| `randomizeSeed()`, `resetCrop()` | methods | |

It dispatches `configurator-change` (`bubbles`, `composed`) with
`{ value, draft, valid, error }` after every edit. Programmatic changes (`value`,
`src`, `locale`, `disabled`) do not dispatch it; `kinds` does only when it
removes the selected mode. `createConfigurator(target, options)` is
the same controller without the element.

Client-side validation mirrors the PHP rules and messages
(`conformance/build-config.json`) and the generator's parameter ranges. The
server must still validate: `BuildConfiguration::fromJson()` and the builder
reject anything the browser sends that does not fit the image or the budgets.

Input:

- Mouse, pen or touch: drag the frame to move the crop, drag a corner to
  resize it.
- Keyboard: every value has a labelled field. On the focused frame, arrow
  keys move the crop and Shift+arrow keys resize it, by 2% of the shorter
  side.

The preview draws the integer cell grid the generator will use (centred, with
square cells when rotation turns by 90°). Jigsaw outlines are only drawn at
generation time, so the preview shows their grid.

## No-bundler use

`dist/image-puzzle-player.iife.js` defines the global `ImagePuzzle`, which
contains `createPlayer`, `createConfigurator`, the `define*` functions,
`createEngine`, `parseBuildConfig`, the error classes and the message
helpers. It also registers both elements.
`dist/image-puzzle-player.bundle.js` is the same code as one ES module. Types
for the global: `import type {} from '@offline-agency/image-puzzle-player/global'`.

Definitions are fetched, so pages must be served over HTTP: `file://` pages
cannot fetch JSON in current browsers. The offline example in
`examples/player/` works from any local static server, for example
`php -S 127.0.0.1:8000` at the repository root. Then open
`/examples/player/index.html` (plain JS) or `/examples/player/element.html`
(Web Component). It needs no network access. Regenerate its assets with
`php examples/player/generate.php`.

## Browser support

Supported: evergreen Chromium (Chrome and Edge 111+), Firefox 113+ and
Safari 16.4+, on desktop and mobile. These versions provide custom elements,
open shadow roots, CSS `aspect-ratio`, `:focus-visible`, Pointer Events and
`AbortController`, which the player uses without polyfills. Older browsers
are not supported.

CI runs the browser suite (`packages/player/e2e`) on these Playwright
projects:

| Project | Engine | Input exercised |
| --- | --- | --- |
| chromium | Desktop Chrome | mouse drag, click, keyboard, axe |
| firefox | Desktop Firefox | mouse drag, click, keyboard, axe |
| webkit | Desktop Safari | mouse drag, click, keyboard, axe |
| mobile-chromium | Pixel 7 emulation (touch) | tap selection and moves, axe |
| mobile-webkit | iPhone 15 emulation (touch) | tap selection and moves, axe |

Not automated:

- Playwright cannot synthesise multi-step touch drags, so dragging pieces
  with a finger on real devices is a manual check.
- Screen-reader output is a manual check.

Manual matrix for releases:

| Check | How |
| --- | --- |
| Touch drag (swap, strips, jigsaw) on iOS Safari and Android Chrome | Drag pieces and tiles; the page must not scroll while dragging a piece, and pinch-zoom/scroll must work outside pieces |
| VoiceOver (Safari) and NVDA (Firefox) | Tile names are read with row/column; moves and completion are announced once |
| Keyboard only | Every control is reachable; focus is always visible (including forced-colors mode) |
| 200 % and 400 % browser zoom | Board scales, toolbar wraps, no clipped controls |

Automated axe checks cover WCAG 2.1 A/AA rules that can be detected
mechanically. They do not certify accessibility.
