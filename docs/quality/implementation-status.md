# Implementation status

Branch `feat/remediation-modes-releases`, from `1.0.2` (`b114a4a`). Defects
of the 1.0.2 code are tracked in [gap-matrix.md](gap-matrix.md) (G01–G28),
new features in [feature-matrix.md](feature-matrix.md) (F01–F26), and fresh
command results in [verification.md](verification.md). Decisions are in
[docs/adr](../adr).

| Phase | Scope | State | Commits |
| --- | --- | --- | --- |
| 0 | Reproduce G01–G18 on 1.0.2 | Done ([baseline.md](baseline.md)) | `c07e25f` (repro scripts, later removed) |
| 1 | Packaging, EXIF, formats, PHP/tooling policy | Done | `c07e25f`, `29896c2`, `fc509ec` |
| 2 | Rounded corners, dimensions, indices, resource limits | Done | `c07e25f` |
| 3 | Export preflight, path confinement, atomic publication | Done | `c07e25f`, `19209b0` |
| 4 | Worker lifecycle and parallel semantics | Done | `c07e25f`, `19209b0`, `f1a7d76`, `1a3fcbc`, `68c3875`, `5a18b2d` |
| 5 | Real shared-edge jigsaw geometry | Done | `c07e25f`, `85af13f` |
| 6 | Schemas, contracts, static analysis, encoding | Done | `85af13f`, `e9415a2`, `c7fb930`, `f0fd36a`, `9e1d8b2` |
| 7 | CI matrix, Docker, consumer test | Done | `4395c24`, `fc509ec`, `68c3875` |
| 8 | Game definitions; sliding, swap, rotation, strips, jigsaw | Done | `43c10c6` |
| 9 | Headless TypeScript engine with PHP conformance | Done | `59a4d4f` |
| 10 | Plain JavaScript player and Web Component | Done | `c9b1b0b` |
| 11 | Livewire and Filament | Done | `9a48239`, `e4d61c0`, `514f318` |
| 12 | React and Vue | Done | `83ebe6d` |
| 13 | CLI, ZIP bundles, WebP, resolutions, atlases, SVG, PDF | Done | `3fce93e`, `5c3729d`, `46d30a6`, `cd8f1a5`, `8069cf7` |
| 14 | Hexagonal/triangular jigsaw, memory, tangram, nonogram | Done | `1d54b9e`, `4683c12`, `8b86a5a`, `4a1355b` |
| 15 | Batch, cache, storage, authoring | Done | `50baa25`, `e4d61c0`, `514f318`, `68c3875` |
| 16 | Documentation, demos, CodeIgniter, discovery | Done in the repository; GitHub metadata needs a maintainer ([RUNBOOK](../release/RUNBOOK.md#repository-metadata)) | `0f46e32`, `3c88fef`, `1b19bcb`, `b48ed2c`, `964431a` |
| 17 | Automatic tag and release per merged pull request | Implemented and tested; **not active**: `master` does not exist ([RUNBOOK](../release/RUNBOOK.md#current-state)) | `4357dbc`, `939d234` |
| 18 | Adoption measurement kit | Done (method and dated baseline; no runs) | `beadcb3` |
| 19 | Matrices, verification, adversarial review, pull request | Done: adversarial review of the release automation (fixed in `939d234`), G15/G16 follow-ups, matrices and fresh verification | `939d234`, `5a18b2d`, `9e1d8b2`, this change |

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
