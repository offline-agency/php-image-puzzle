# The 60–90 day experiment

One concrete use case, one tutorial and one demo, measured stage by stage.
The aim is to tell four failures apart: people do not find the library
(discovery), find it but do not install it (positioning), install it but
fail (correctness or documentation), or succeed once and leave (product
value).

## Preconditions

Start day 0 only when all of these hold. Write the date of each in the day 0
checkpoint.

1. The release automation is active and `2.0.0` is on Packagist
   (`php tools/release/verify-packagist.php --version=2.0.0 --sha=<sha>`
   passed).
2. The repository description, topics and homepage are set
   ([RUNBOOK](../release/RUNBOOK.md#repository-metadata)).
3. A maintainer can read GitHub traffic (`gh api …/traffic/views`).
4. The baseline commands were re-run on day 0.

## Use case

> A PHP web application lets a user upload a photo and play it as a jigsaw
> puzzle in the browser, with progress saved on the server.

This is the path of [docs/TUTORIAL.md](../TUTORIAL.md). The demo is a
static site generated from one picture with
`php examples/demo/build.php <out> <image>`
([examples/demo](../../examples/demo/README.md)), which needs no
framework. Framework-specific entry points (Livewire, Filament,
CodeIgniter, React, Vue) stay as they are. The experiment measures the
single path above.

## Measurements

| Stage | Measurement | How | When |
| --- | --- | --- | --- |
| Discovery | Views, unique visitors, top referrers | `gh api repos/offline-agency/php-image-puzzle/traffic/views` and `…/popular/referrers` (GitHub keeps 14 days: collect at least every 14 days) | every 14 days |
| Discovery | Search position for fixed queries | By hand, logged out, in a private window: Packagist search for `image puzzle`, `jigsaw puzzle`, `sliding puzzle`; a web search for `php jigsaw puzzle library`. Record the position or "not in top 20" | day 0, 30, 60, 90 |
| Install attempt | Installs (daily, monthly, total) | `curl -s https://packagist.org/packages/offline-agency/image-puzzle.json` (`downloads`); `…/stats/all.json` if available | every 14 days |
| Install attempt | Clones | `gh api …/traffic/clones` | every 14 days |
| First output | Reported first runs and failures | New issues and discussions, labelled `first-run` by the maintainer when they describe a first attempt (success or failure) | continuous |
| First output | Agent integration correctness | [agent-evaluation](agent-evaluation/README.md) runs | day 0 and day 60–90 |
| Continued use | Dependents and public users | Packagist dependents; GitHub code search `"offline-agency/image-puzzle" path:composer.json` excluding this repository | day 30, 60, 90 |
| Confirmed project | External project links | Only with a public URL or a statement from that project's maintainer | continuous |

Do not add analytics scripts, tracking pixels or telemetry to the library,
the demo or the documentation. The measurements above use what GitHub and
Packagist already publish.

## Checkpoints

Use [checkpoint-template.md](checkpoint-template.md) on day 0, 30, 60 and
90, saved as `checkpoints/<date>.md` in this directory.

## Decision rules at day 60–90

| Observation | Likely problem | Next step |
| --- | --- | --- |
| Views flat, few referrers | Discovery | Work on the description, topics, Packagist keywords and a tutorial placed where PHP developers search |
| Views up, installs flat | Positioning | Revisit the README opening and [WHY.md](../WHY.md): does it say what the library does in one sentence? |
| Installs up, first-run failures reported | Correctness or documentation | Fix the reported step; add a test for it in `tests/Docs` |
| First runs succeed, no dependents after 60 days | Product value or timing | Ask in the existing issues what was missing; consider the framework adapters people asked about |
| Agents shortlist it but integrate it wrongly | Documentation for agents | Update [AGENTS.md](../../AGENTS.md) and [llms.txt](../../llms.txt), then re-run the same prompts |

The exploratory target is 2–5 confirmed external projects by day 90. Missing
it is a result to explain with the table above, not a failure to hide.
