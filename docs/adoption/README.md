# Measuring discoverability and adoption

This directory holds a method, not results. It lets a maintainer find out,
with evidence, whether people who need "turn an image into a playable
puzzle" find this library, install it, get a correct first result, and keep
using it. It also covers whether coding agents recommend it and integrate it
correctly. Nothing here has been run yet, apart from the dated baseline.

| File | Purpose |
| --- | --- |
| [baseline-2026-10-25.md](baseline-2026-10-25.md) | Public numbers observed before any promotion, with sources |
| [experiment.md](experiment.md) | A 60–90 day experiment around one use case, with checkpoints and decision rules |
| [agent-evaluation/README.md](agent-evaluation/README.md) | Protocol for testing coding-agent recommendations and integrations |
| [agent-evaluation/prompts.md](agent-evaluation/prompts.md) | The fixed, unbranded prompt set |
| [agent-evaluation/run-log.csv](agent-evaluation/run-log.csv) | Empty log with the required columns |
| [checkpoint-template.md](checkpoint-template.md) | The form for each dated checkpoint |

## The funnel

Each stage has its own evidence. A number from one stage is never reported
as another stage.

| Stage | Question | Evidence | Not evidence |
| --- | --- | --- | --- |
| Discovery | Do people reach the project? | GitHub traffic views and referrers (14-day window, maintainer access), Packagist page views if available, search result positions recorded by hand | Stars alone |
| Install attempt | Do they try it? | Packagist installs (daily and monthly), GitHub clones | Unique users: CI, caches, mirrors and repeated installs inflate these counts |
| First output | Does the first try work? | Issues and discussions describing a first run, agent-evaluation integration scores, the demo site builder run by a tester | Installs |
| Continued use | Do they keep it? | Packagist dependents, public repositories requiring the package (GitHub code search for `offline-agency/image-puzzle` in `composer.json`), repeat issue reporters | One-off installs |
| Confirmed external project | Is it used for real? | A public project or a written statement from its maintainer, linked in the checkpoint | Forks, stars, downloads |

Install counts are **not** unique users: one developer with three
environments and a CI matrix produces many installs. Report installs as
installs.

## Rules

- Record dates, sources and the exact command or URL for every number.
- Keep the raw observation next to any interpretation.
- Low numbers for neighbouring packages (see the baseline) describe how
  small the niche looks on Packagist. They are not a market-size estimate.
- Do not claim the library is the first choice, recommended or endorsed by
  any agent, vendor or framework. Report what a dated run showed, with its
  configuration.
- The exploratory goal of 2–5 confirmed external projects is a target for
  judging the experiment. It is not a forecast.
- This kit schedules nothing, collects no personal data and contacts nobody.
  Promotion, announcements and outreach are separate maintainer decisions.
