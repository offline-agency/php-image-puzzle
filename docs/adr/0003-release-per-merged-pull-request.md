# ADR 0003: One release per merged pull request, reconciled from master history

Status: accepted (2026-10-25)

## Context

Every pull request merged into `master` must produce one stable SemVer tag
and one GitHub Release, after the exact accepted commit passed CI. GitHub
events can arrive late, out of order, twice or not at all. CI runs for
consecutive merges can finish in any order. The workflow token can write
tags, so the writing step must not run contributor code. Releases `1.0.0` to
`1.0.2` already exist as lightweight, unprefixed tags.

## Decision

- **Reconcile instead of reacting.** Every trigger (`pull_request_target`
  closed, `workflow_run` of CI, `workflow_dispatch`) only starts a planner
  that reads the live state. The planner walks `master` from a fixed anchor
  (`1.0.2` at `b114a4a`) and maps commits to merged pull requests through
  the API. It plans the oldest pull request without a release. Order comes
  from the branch history, never from event arrival.
- **Exact-SHA gate bound to an identity.** A commit counts as validated
  only when a `push` run of `.github/workflows/ci.yml` on `master` in this
  repository succeeded and its `CI gate` job succeeded. With no such run,
  the release workflow calls the same CI through `workflow_call` for that
  SHA.
- **The repository is the database.** The annotated tag message and an HTML
  comment in the release body record the pull request, accepted SHA,
  version, predecessor, bump and reason, and the tooling revision. A rerun
  reads them back. An existing matching tag is completed, never re-allocated.
  A contradicting tag stops the run. `SHA256SUMS`, uploaded last, marks a
  complete release. Releases are created in branch order, so a plan starts
  from the newest release in the history of the head; its API cost does
  not grow with the number of releases.
- **Privilege split.** Planning, validation and artifact builds are
  read-only. One publish job holds `contents: write`, runs only the trusted
  tooling from the planned `master` revision, plans again, and refuses if
  the plan changed or the artifacts do not match the manifest checksums.
- **Versioning in data, checked before the merge.** `release:*` labels and
  Conventional Commits decide the bump, with patch as the floor. The same
  classifier runs as a pull request check, so contradictions fail before
  the merge. Labels are read again at release time; they are not frozen at
  merge time, which keeps the state in one place (GitHub) at the cost of
  letting a maintainer change a label between merge and release.
- **Plain PHP tooling.** The planner and publisher are dependency-free PHP
  (`tools/release`) behind a small `GitHub` interface. They are exercised by
  scenarios against an in-memory fake and temporary Git repositories,
  without a release action from the marketplace.

## Consequences

- A lost event delays a release until the next event or a dispatch. It
  never skips one. A failing commit blocks later releases until it is fixed
  or explicitly excluded in the policy file through a reviewed change.
- The workflow is inert until the default branch is `master`
  ([RUNBOOK](../release/RUNBOOK.md#activation-from-main-to-master)).
- `actionlint` 1.7.12 does not know `concurrency.queue`, so that one message
  is ignored when linting.
- Lockstep versions for the PHP package, browser bundles, npm tarballs and
  integration archives follow from "one release per pull request"
  ([ADR 0004](0004-lockstep-distribution.md)).
