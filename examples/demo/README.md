# Demo site

`build.php` turns one picture into a static site with a puzzle for every game
mode, the player script and an index page with a mode menu. Use it to show the
library to others with a picture of your own, or as a starting point for a
puzzle page.

```bash
php examples/demo/build.php build/demo                                   # in this repository
php vendor/offline-agency/image-puzzle/examples/demo/build.php public/demo photo.jpg --title="Team puzzles"
php -S 127.0.0.1:8000 -t build/demo                                      # then open http://127.0.0.1:8000
```

| Option | Default | Meaning |
| --- | --- | --- |
| `<output-dir>` | required | New or empty directory for the site |
| `[image]` | `docs/images/showcase/source.png` (repository only) | The picture every mode is built from |
| `--title=TEXT` | `Image puzzles` | Page title and heading |
| `--credit=TEXT` | none, or a note for the default picture | Picture credit shown in the footer |
| `--preset=easy\|medium\|hard` | `easy` | Difficulty preset for every mode |
| `--seed=N` | `1` | Seed, so the same picture gives the same site |

The site needs nothing but static hosting: the definitions and piece images
are files, the player is `image-puzzle-player.iife.js` from the installed
package, and progress is kept in the visitor's browser. Open it over HTTP,
because the page fetches the definitions; for a single file that also works
from `file://`, use `image-puzzle bundle` on one puzzle instead.

A mode the picture cannot produce is skipped with a message (for example, a
nonogram needs a picture with clear shapes). Only publish pictures you have
the right to share.

`packages/player/e2e/demo.spec.ts` builds the site and plays every mode in the
five browser projects, and `tools/consumer/cli.php` runs the builder from an
installed copy of the package.
