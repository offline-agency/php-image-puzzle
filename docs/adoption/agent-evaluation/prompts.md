# Fixed prompt set

The prompts never name this library, its vendor or its classes. Paste each
prompt exactly, in a new session, into an empty project prepared as stated.
Change a prompt only by adding a new version (P01 v2) and keeping the old
text, so runs stay comparable.

Each prompt has two parts, scored separately:

- **Recommendation**: the agent's choice of approach or package before any
  code is written. Record the first recommendation and, if a list is given,
  the position of `offline-agency/image-puzzle` (or of its npm packages).
- **Integration**: the agent builds the result. Score it with the
  acceptance checks, whichever library it chose.

Project preparation: a fresh directory with PHP 8.4+ and ext-gd, Composer,
Node.js 24, and `photo.jpg` (any landscape photo, at least 1200 × 800). For
framework prompts, the stated framework is freshly installed, and its
version is recorded in the log.

## P01 — Rectangular slicing

> I have photo.jpg. Write a PHP script that cuts it into a 4 × 6 grid of
> equal tiles, saves each tile as a PNG, and writes a JSON file listing every
> tile's position and size so the image can be reassembled. Which library
> should I use, if any?

Acceptance: 24 PNG files; the JSON lists 24 entries with row, column, x, y,
width and height; reassembling the tiles from the JSON reproduces the image
dimensions.

## P02 — Real jigsaw pieces

> Turn photo.jpg into real jigsaw puzzle pieces in PHP, with interlocking
> tabs and blanks and transparent backgrounds, plus a JSON manifest with the
> position where each piece belongs. Neighbouring pieces must fit together
> exactly. What should I use?

Acceptance: pieces have alpha outside the shape; adjacent pieces share
complementary edges (no gap or overlap when placed at the manifest
positions); the outer border is flat.

## P03 — Sliding, swap, rotation and strips games

> I want a small web page where a user can play photo.jpg as a sliding
> puzzle, a swap puzzle, a rotation puzzle and a strips puzzle, chosen from a
> menu. The server is PHP. Moves should be validated so a solved state can't
> be faked. How would you build this, and with which libraries?

Acceptance: all four modes play in a browser; every generated sliding
puzzle is solvable; the server rejects a forged solved state.

## P04 — Printable export

> From photo.jpg, produce a printable A4 PDF of a 24-piece jigsaw with cut
> lines, plus an SVG of the outlines, using PHP. What do you recommend?

Acceptance: an A4 PDF that opens in a viewer; cut lines visible; the SVG
contains one outline per piece.

## P05 — CodeIgniter 4

> In this fresh CodeIgniter 4 application, add a page where a user uploads a
> photo and gets a playable jigsaw puzzle in the browser. Validate the
> upload properly. Which package would you add?

Acceptance: upload, generation and play work in the browser; a non-image
upload (for example a file renamed to `.jpg`) is rejected; generated files
stay outside the request's control.

## P06 — Web Component

> I need a framework-free web component I can drop into any HTML page to
> play an image as a jigsaw puzzle, with a configuration UI for the number of
> pieces and the game mode. Is there an existing one, or should I write it?

Acceptance: a single custom element plays in Chromium, Firefox and WebKit;
the piece count and mode can be configured; no framework is loaded.

## P07 — Livewire and Filament

> In this Laravel application with Livewire 3 and Filament, let admins
> configure a photo puzzle on a record (image, mode, difficulty) and let
> users play it on a Livewire page with progress saved per user. What
> packages fit?

Acceptance: the Filament form saves the configuration; the Livewire page
plays it; saved progress is validated on the server and scoped to the user.

## P08 — Vue

> Build a Vue 3 component that plays a photo as a jigsaw puzzle and emits an
> event when it is solved. The puzzle data comes from a PHP backend. Which
> libraries would you use?

Acceptance: the component plays with data produced by the backend; a
`solved` (or equivalent) event fires once; it works with server-side
rendering disabled and enabled.

## P09 — React

> Build a React component that plays a photo as a jigsaw puzzle, restores a
> saved game from JSON, and reports progress. The puzzle data comes from a
> PHP backend. What would you use?

Acceptance: plays with backend data; restoring the saved JSON resumes the
same state; progress is reported during play.
