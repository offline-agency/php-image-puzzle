# Implementation status

Branch `feat/remediation-modes-releases`, from `1.0.2` (`b114a4a`). Defects
of the 1.0.2 code are tracked in [gap-matrix.md](gap-matrix.md) (G01–G28),
new features in [feature-matrix.md](feature-matrix.md) (F01–F26), and fresh
command results in [verification.md](verification.md). Decisions are in
[docs/adr](../adr).

| Phase | Scope | State | Commits |
| --- | --- | --- | --- |
| 0 | Reproduce G01–G18 on 1.0.2 | Done ([baseline.md](baseline.md)) | `ea32bd4` (repro scripts, later removed) |
| 1 | Packaging, EXIF, formats, PHP/tooling policy | Done | `ea32bd4`, `6af8d8a`, `5489af3` |
| 2 | Rounded corners, dimensions, indices, resource limits | Done | `ea32bd4` |
| 3 | Export preflight, path confinement, atomic publication | Done | `ea32bd4`, `5a4cfb0` |
| 4 | Worker lifecycle and parallel semantics | Done | `ea32bd4`, `5a4cfb0`, `a595e2f`, `d1c265a`, `266abd6`, `4a62285` |
| 5 | Real shared-edge jigsaw geometry | Done | `ea32bd4`, `67d384b` |
| 6 | Schemas, contracts, static analysis, encoding | Done | `67d384b`, `9d19add`, `7fd41ec`, `54f522d`, `5a17398` |
| 7 | CI matrix, Docker, consumer test | Done; local Docker run blocked (see below) | `c6d5a17`, `5489af3`, `266abd6` |
| 8 | Game definitions; sliding, swap, rotation, strips, jigsaw | Done | `0a0b7ce` |
| 9 | Headless TypeScript engine with PHP conformance | Done | `9741c89` |
| 10 | Plain JavaScript player and Web Component | Done | `74fc66c` |
| 11 | Livewire and Filament | Done | `ad5766d`, `a9196db`, `92a779e` |
| 12 | React and Vue | Done | `350879a` |
| 13 | CLI, ZIP bundles, WebP, resolutions, atlases, SVG, PDF | Done | `870eada`, `0377423`, `bca000d`, `e35385d`, `d02c984` |
| 14 | Hexagonal/triangular jigsaw, memory, tangram, nonogram | Done | `c377d48`, `f705df7`, `b05fd51`, `d4f11cd` |
| 15 | Batch, cache, storage, authoring | Done | `f705ef6`, `a9196db`, `92a779e`, `266abd6` |
| 16 | Documentation, demos, CodeIgniter, discovery | Done in the repository; GitHub metadata needs a maintainer ([RUNBOOK](../release/RUNBOOK.md#repository-metadata)) | `ff555bf`, `ea9b80a`, `b18b409`, `be135d2`, `ff1bd08` |
| 17 | Automatic tag and release per merged pull request | Implemented and tested; **not active**: `master` does not exist ([RUNBOOK](../release/RUNBOOK.md#current-state)) | `6ccfdb2`, `e053ac8` |
| 18 | Adoption measurement kit | Done (method and dated baseline; no runs) | `84bcfc8` |
| 19 | Matrices, verification, adversarial review, pull request | Done: adversarial review of the release automation (fixed in `e053ac8`), G15/G16 follow-ups, matrices and fresh verification | `e053ac8`, `4a62285`, `5a17398`, this change |

## Blockers outside this session

These need repository or registry administration, which this task was not
authorized to perform. Each has an executable procedure.

| Blocker | Effect | Procedure |
| --- | --- | --- |
| No `master` branch; default branch is `main` | Release workflow inactive | [RUNBOOK: activation](../release/RUNBOOK.md#activation-from-main-to-master) |
| Required checks and tag rules not configured | Pre-merge release classification not enforced | [RUNBOOK: branch and tag rules](../release/RUNBOOK.md#3-branch-and-tag-rules) |
| GitHub description, topics and homepage empty | Discovery metadata missing on GitHub (Composer metadata is done) | [RUNBOOK: repository metadata](../release/RUNBOOK.md#repository-metadata) |
| `1.0.1` and `1.0.2` absent from Packagist | Composer users see `1.0.0` until the next release | Fixed for future tags. Old tags stay as they are ([RUNBOOK: current state](../release/RUNBOOK.md#current-state)) |
| No npm organization, no split Packagist repositories | npm and adapter packages are attached to releases, not in registries | [RUNBOOK: Packagist and npm](../release/RUNBOOK.md#packagist-and-npm) |
| GitHub traffic needs maintainer access | Adoption baseline lacks traffic numbers | [adoption experiment](../adoption/experiment.md) |
| Docker Desktop VM disk full on the development machine | Docker images, coverage (pcov is only in the image) and mutation testing not run fresh; CI runs them | [gap matrix: G28](gap-matrix.md#g28-reproducible-development-environment) |
