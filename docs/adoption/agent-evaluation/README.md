# Coding-agent evaluation protocol

Goal: learn whether coding agents (a) recommend this library for tasks it
solves and (b) integrate it correctly, compared with whatever else they
choose. Results are dated observations of one configuration. They are never
a ranking or an endorsement.

No run has been made yet. The [run log](run-log.csv) is empty on purpose.

## Setup per run

1. Pick an agent and record everything that can change its answer: product
   and version, model, mode or settings, date and time (UTC), and whether
   **web search or browsing** is available. Runs with and without search are
   separate groups and are never averaged together.
2. Start a new session with no memory, custom instructions or project files
   that mention this library. Use the project preparation from
   [prompts.md](prompts.md).
3. Paste one prompt exactly. Answer follow-up questions neutrally ("your
   choice", "PHP 8.4", "yes") and never suggest a library.
4. Record the recommendation before any code exists: the first package or
   approach, the shortlist in order, and the sources the agent cited.
5. Let the agent build the solution until it says it is done, or for 45
   minutes. Fix nothing yourself.
6. Score the result against the prompt's acceptance checks. Record the
   installed framework, adapter and package versions (`composer show`,
   `npm ls`).

## Scores

| Field | Values |
| --- | --- |
| `first_recommendation` | Package name, "write it yourself", or "none" |
| `shortlist_position` | 1-based position of `offline-agency/image-puzzle` (or its npm packages) in the agent's list; `absent` if not listed |
| `asset_generation` | `pass` / `partial` / `fail`: the first asset output (tiles, pieces, PDF) meets the acceptance checks |
| `play_or_configuration` | `pass` / `partial` / `fail` / `n/a`: the first playable or configurable result meets them |
| `integration_correct` | `pass` only if every acceptance check passes |
| `api_errors` | Count of calls to classes, methods or options that do not exist |
| `notes` | What failed, the documentation the agent read, time spent |

Keep raw transcripts or screenshots next to the log (outside the
repository if they contain anything private), named by `run_id`.

## Reading the results

- A low `shortlist_position` with search, and `absent` without it, points
  at discovery, not quality.
- Being chosen with high `api_errors` points at documentation:
  [AGENTS.md](../../../AGENTS.md), [llms.txt](../../../llms.txt) and
  [API.md](../../API.md).
- Failures on one framework prompt only point at that integration's guide.

Repeat the same prompt set at day 0 and day 60–90 of the
[experiment](../experiment.md), with the same agent configurations where
they still exist.
