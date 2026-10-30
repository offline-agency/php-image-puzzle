# Release runbook

Every pull request merged into `master` becomes one release: an annotated
tag and a GitHub Release at the exact accepted commit, versioned from the
pull request's labels and Conventional Commit metadata, published in
`master` order after that commit passed CI. Nobody edits a version, runs a
tag command or opens a release pull request.

This document is for maintainers. It covers the current activation state,
the one-time switch from `main` to `master`, the version policy, recovery,
and distribution through Packagist and npm.

## Current state

Observed on 2026-10-25 through the public GitHub and Packagist APIs:

| Item | State |
| --- | --- |
| Release automation | **Not active.** The workflow listens to `master`, and the repository has no `master` branch yet. |
| Branches | `main` only (default branch); the change that adds this workflow is pull request #3 |
| Release labels | `release:major`, `release:minor`, `release:patch` exist (since 2026-10-30) |
| Release tags | `1.0.0` (`e1c0138`), `1.0.1` (`61b6594`), `1.0.2` (`b114a4a`), all lightweight |
| Activation anchor | `1.0.2` at `b114a4a2d0cc0b144e1c07b713142fd82717c74e` (`.github/release-policy.json`). Earlier history is never released again. |
| Packagist `offline-agency/image-puzzle` | Lists `1.0.0` and `dev-main` (at `b114a4a`). **`1.0.1` and `1.0.2` are missing.** |
| npm packages, Livewire/Filament on Packagist | Not published |

Why `1.0.1` and `1.0.2` are missing from Packagist: the `composer.json` in
both tags declares `"version": "1.0.0"`, and Packagist skips a tag whose
declared version disagrees with it. `dev-main` is current, so the GitHub
hook itself works. `composer.json` no longer has a `version` field, and the
artifact build refuses one (`ArtifactBuilder::composerProblems()`), so tags
created by the workflow are indexed. The existing tags are left as they are;
tags are never moved.

Until the activation steps below are complete, describe the feature as
"implemented and tested, not active".

## How a release happens

`.github/workflows/release-on-merge.yml` (`name: Release`) starts on:

- a pull request into `master` being closed (`pull_request_target`);
- a `CI` run on `master` completing (`workflow_run`), so a release whose CI
  was still running is picked up when CI finishes;
- `workflow_dispatch` with `pr_number` (and `dry_run`, true by default), for
  bootstrap and recovery.

All three only start a reconciliation. The event data is never trusted:

1. **plan** (read-only) checks out the trusted `master` revision, records
   its SHA as the tooling revision, and runs `tools/release/plan.php`. The
   planner walks `master` from the anchor to the head, maps each commit to
   its merged pull request (re-queried through the API: merged, base
   `master`, this repository, accepted merge SHA in the history), and finds
   the first pull request without a release. That pull request is planned,
   whichever one the event mentioned, so releases follow `master` order even
   when CI runs finish out of order.
2. The accepted commit must have a successful `push` run of
   `.github/workflows/ci.yml` on `master` in this repository whose
   `CI gate` job succeeded. Pending means wait (the `workflow_run` event
   comes back). Failed, cancelled, skipped or missing gate jobs block. A run
   of another workflow, event or branch does not count.
3. **validate**: if no such run exists, the workflow calls `ci.yml` through
   `workflow_call` with the exact SHA instead of waiving the gate. This is
   rare: CI concurrency groups are per commit, so a later merge never
   cancels the run of an earlier one. Validation is read-only, so a dry run
   validates too.
4. **artifacts** (read-only) checks out the accepted commit separately from
   the tooling, installs dependencies, builds the player and runs
   `tools/release/build-artifacts.php` and `tools/release/smoke-artifacts.sh`.
5. **publish** is the only job with `contents: write`. It installs and runs
   nothing from the package. A release that is already complete for the
   planned pull request, commit and version is left alone, so re-running a
   successful run writes nothing. Otherwise it plans again from live data,
   refuses to continue if anything changed, verifies every artifact
   checksum against the manifest, creates the annotated tag at the accepted
   SHA, creates the GitHub Release, and uploads the assets. Unfinished or
   mismatching assets (size or GitHub's SHA-256 digest) are replaced;
   `SHA256SUMS` is removed first and uploaded last. An uploaded asset that
   is not part of the build stops the run and is left in place.
6. **continue** dispatches the workflow for the next pull request if one is
   already validated (`workflow_dispatch` is the one event a `GITHUB_TOKEN`
   may start).
7. **verify-distribution** (read-only) waits for Packagist with bounded
   backoff and installs exactly the new version in a fresh project.

Runs are serialized by `concurrency: release-master` with
`cancel-in-progress: false` and `queue: max`. GitHub keeps at most 100
waiting runs per group. A dropped run loses nothing, because any later run,
or a dispatch for any merged pull request, reconciles the whole backlog in
order.

### What each release records

The annotated tag message, and an HTML comment in the release body, carry:

```text
image-puzzle-release: 1
pull-request: 123
accepted-sha: <40 hex>
version: 1.0.3
predecessor: 1.0.2
bump: patch
reason: no feature or breaking change (patch by default)
tooling-sha: <40 hex>
```

The release notes give the pull request, accepted commit, previous version,
bump reason, the commit subjects, commits pushed without a pull request, a
migration section for major releases (link to `docs/MIGRATION.md` plus the
pull request's `## Migration` section, quoted), the artifacts with their
checksums, and the shipped modes and package versions.

## Version policy

Tags are unprefixed `X.Y.Z`, as `1.0.0` to `1.0.2` already are. Every merged
pull request gets a release, so documentation, CI and dependency changes
produce at least a patch release. There is no skip label.

| Source | Bump |
| --- | --- |
| `type!:` in the title or a commit subject, or a `BREAKING CHANGE:` footer in the body or a commit | major |
| `feat:` / `feat(scope):` in the title or a commit subject | minor |
| Anything else | patch |
| Label `release:major`, `release:minor` or `release:patch` | That level |

- A label can raise the level; a label below the detected level is
  rejected (`release:patch` on a `feat!:` change).
- One release label at most; unknown `release:*` labels are rejected.
- Changes to the `php` or `ext-*` requirements in `composer.json`, or edits
  to existing files under `docs/schemas/`, need a label. A maintainer
  decides whether they break users (`release:major`) or not.
- The **Release policy** workflow (`.github/workflows/release-policy.yml`)
  runs these checks on every pull request event, so contradictions fail
  before the merge. The planner runs them again at release time, with the
  labels, title and body as they are then: editing them after the merge
  changes the next plan, and the edit is visible in the pull request's
  history. The bump also counts the accepted commit's own message (an
  edited squash message) and commits pushed without a pull request.
- A pull request with 250 or more commits or 3000 or more changed files
  can exceed what the API lists; it needs an explicit label.

**Pre-1.0 rule.** If the lineage had no stable tag, the first release
would be `0.1.0`. Below `1.0.0`, a breaking change bumps the minor version
and everything else the patch version. Only an explicit `release:major`
label moves to `1.0.0`. The repository is already at `1.0.2`, so normal
SemVer applies.

The predecessor is the highest stable tag that is an ancestor of the
accepted commit. A stable tag at the accepted commit, or a higher stable tag
outside that history, stops the run for a maintainer to resolve. Nothing is
picked silently.

### Lockstep versions

One release covers the whole repository at one version:

| Component | Distribution at version X.Y.Z |
| --- | --- |
| `offline-agency/image-puzzle` | Composer, from tag `X.Y.Z` (Packagist) |
| Browser player (IIFE and ES module) | Committed in `resources/dist` and `integrations/livewire/resources/dist`, so Composer installs include them, and attached to the release |
| `@offline-agency/image-puzzle-engine`, `-player`, `-react`, `-vue` | npm tarballs attached to the release, packed at `X.Y.Z` with internal dependencies pinned to exactly `X.Y.Z` |
| `offline-agency/image-puzzle-livewire`, `-filament` | ZIP archives of `integrations/*` at the tag, attached to the release |
| Schemas (`docs/schemas`) and game modes | Shipped by the PHP package; the manifest lists the modes (`Modes::available()`) |

The workspaces keep `0.0.0-dev` in `package.json`. Only the packed copies
get the release version. The integrations require
`offline-agency/image-puzzle: ^2.0`.

The committed browser bundles must equal a fresh build of the accepted
commit (CI job **TypeScript packages** and the artifact build both check).
The release never rewrites a tagged commit to add a bundle.

## Activation: from `main` to `master`

Do these steps in order, as a maintainer with admin rights. They change the
production repository, so they are not part of the implementation pull
request.

### 1. Check the starting point

```bash
gh api repos/offline-agency/php-image-puzzle --jq .default_branch    # main
gh api repos/offline-agency/php-image-puzzle/branches/master          # expect 404
```

If `master` exists by then, do not rename. Check how it relates to `main`:

```bash
gh api repos/offline-agency/php-image-puzzle/compare/master...main --jq '.status, .ahead_by, .behind_by'
```

- `identical` or `ahead` (main is ahead of master): fast-forward `master`
  to `main` through a pull request or `git push origin main:master`, then
  switch the default branch (`gh repo edit --default-branch master`) and
  continue with step 3.
- `behind` or `diverged`: stop. Someone else uses `master`, and merging
  histories is a maintainer decision.

### 2. Rename `main` to `master`

```bash
gh api -X POST repos/offline-agency/php-image-puzzle/branches/main/rename -f new_name=master
```

GitHub's branch rename keeps every commit and tag. It retargets open pull
requests, updates branch protection rules that name the branch, keeps the
default branch, and redirects web links to `main`. Afterwards, check:

```bash
gh api repos/offline-agency/php-image-puzzle --jq .default_branch                 # master
gh pr list --state open --json number,baseRefName --jq '.[] | select(.baseRefName != "master")'
gh api repos/offline-agency/php-image-puzzle/rulesets --jq '.[] | {id, name, target}'
```

Rulesets that name `refs/heads/main` explicitly are not renamed. Edit each
one to `refs/heads/master`, or to `~DEFAULT_BRANCH`.

Every clone then runs:

```bash
git branch -m main master
git fetch origin
git branch -u origin/master master
git remote set-head origin -a
```

### 3. Branch and tag rules

On `master`, require the status checks **CI gate** and **Release
classification**, and block force pushes and deletion. Keep the default
workflow permissions read-only (Settings → Actions → General). No personal
token or extra secret is needed.

A tag ruleset for `*.*.*` that blocks updates and deletions protects
published versions. Creation must stay allowed for GitHub Actions.

The release labels must exist (created on 2026-10-30; re-create them on a
fork or a new repository):

```bash
gh label create release:major --color B60205 --description "Release as a major version"
gh label create release:minor --color FBCA04 --description "Release as a minor version"
gh label create release:patch --color 0E8A16 --description "Release as a patch version"
```

### 4. Merge the workflow

Merge the pull request that adds this workflow **after** the rename, so it
is merged into `master`. Pull requests merged into `main` before the rename
do not count as `master` pull requests. Their commits would be released with
the next `master` pull request, listed as commits without a pull request.

### 5. Bootstrap

The first merge may not start the workflow, because GitHub reads the
workflow from the branch that receives the event. Find the merged pull
request and run a dry run, then the real run:

```bash
gh pr list --state merged --base master --limit 5 --json number,mergeCommit,title
gh workflow run release-on-merge.yml --ref master -f pr_number=<N> -f dry_run=true
gh run watch "$(gh run list --workflow release-on-merge.yml --limit 1 --json databaseId --jq '.[0].databaseId')"
```

The run summary shows the pull request, branch, accepted SHA, validation
status and identity, previous version, bump reason, next tag, target SHA,
the release notes and the Packagist command. A dry run writes nothing. If
the summary is right:

```bash
gh workflow run release-on-merge.yml --ref master -f pr_number=<N> -f dry_run=false
```

Running it again is harmless: a released pull request is a no-op, and a
partial release is completed with the same version.

The pull request that adds this workflow replaces the 1.x schema files and
contains the breaking changes listed in `docs/MIGRATION.md`. It needs the
`release:major` label (the schema change requires a label, and the policy
check refuses a lower one together with a `feat!:` title), so its release
will be `2.0.0`.

### 6. Follow-up pull request

After the first release, open a pull request that:

- changes the README CI badge from `?branch=main` to `?branch=master`;
- removes `main` from `on.push.branches` in `.github/workflows/ci.yml` (it
  stays only to keep CI running during the switch);
- updates this "Current state" section.

That pull request is released as a patch, which also checks the steady
state.

## Recovery

| Situation | What happens / what to do |
| --- | --- |
| CI still running for the accepted commit | Plan says `wait`. The `CI` completion event starts the release. |
| CI failed for the accepted commit | Plan says `blocked`, and later pull requests wait. Re-run the failed CI jobs. If they pass, dispatch for the pull request, or wait for the `workflow_run` event. |
| A commit that can never pass | Review it, then add it to `excluded` in `.github/release-policy.json` (`pullRequest`, full `sha`, `reason`) through a pull request. Its changes ship with the next release, which lists the commit. Only failed commits can be excluded. |
| No CI run exists for the accepted commit | The workflow runs CI for that SHA itself (`validate` job). |
| Missed or dropped events, a full queue | `gh workflow run release-on-merge.yml --ref master -f pr_number=<any merged PR> -f dry_run=false` reconciles everything pending, oldest first. |
| Tag created, release creation failed | Re-run the workflow, or dispatch for the same pull request. The existing tag is checked (commit and metadata), the accepted commit must still pass validation, and the release is created for it with the same version. |
| Release exists, assets incomplete | Same. Missing assets are uploaded, unfinished (`starter`) or mismatching ones are replaced; `SHA256SUMS` is uploaded last, so its absence marks an incomplete release. |
| An extra asset on a release | The run stops without touching it. Remove the asset by hand if it does not belong there, then re-run. |
| A tag at another commit or with other metadata | The run fails and touches nothing. Find out who created the tag. Never delete or move a published tag to "fix" automation. |
| A higher stable tag outside the history | The run fails. Remove a tag that was never published, or decide on the version line by hand. |
| Packagist does not list the version | The GitHub Release stays. Check the Packagist hook (below), then re-run **Verify the Composer distribution** or run the command from the plan summary. |
| Wrong artifact (another commit, bad checksum) | Publish refuses before writing. Re-run the workflow to rebuild the artifacts. |

Dry runs and every planner decision are available locally with a read-only
token:

```bash
GITHUB_TOKEN="$(gh auth token)" php tools/release/plan.php --event=workflow_dispatch --pr=<N> \
    --tooling-sha="$(git rev-parse origin/master)"
```

## Packagist and npm

**Packagist, core package.** The package uses GitHub's Packagist hook
(`dev-main` follows `main`). After the rename, check that `dev-master`
appears on <https://packagist.org/packages/offline-agency/image-puzzle>. If
it does not, the maintainer of the Packagist package clicks **Update** there
once, or reconnects the GitHub integration under the Packagist profile
settings. No `PACKAGIST_TOKEN` is used.

After each release, **verify-distribution** runs:

```bash
php tools/release/verify-packagist.php --version=X.Y.Z --sha=<accepted sha>
```

It polls `https://repo.packagist.org/p2/offline-agency/image-puzzle.json` (10
attempts, 15 s doubling to at most 300 s). It then installs exactly `X.Y.Z`
in a temporary project without path repositories, checks that the installed
reference is the accepted SHA, and cuts and exports a puzzle. A failure
leaves the release in place and can be retried as is. The same command runs
against a local Composer repository in the tests (`--repository=file://…`).

**Livewire and Filament packages.** Packagist needs one repository per
package. Until split repositories exist, users install them from the
repository (`docs/LIVEWIRE.md#distribution`), and the release attaches
their archives. To publish them later:

1. Create `offline-agency/image-puzzle-livewire` and `-filament` repositories.
2. Mirror `integrations/<name>` with tags, for example with
   `git subtree split --prefix=integrations/<name> -b split-<name>` and a push
   of that branch and the release tag.
3. Submit each repository on Packagist and enable its GitHub hook.

**npm.** The release attaches the four tarballs. Publishing them needs an
npm organization `@offline-agency` owned by the maintainers. Then, for each
tarball of a release, run from a machine or a dedicated workflow with an npm
token:

```bash
npm publish offline-agency-image-puzzle-engine-X.Y.Z.tgz --access public --provenance
```

Publish in this order: engine, player, react, vue. Until that happens, the
README states that the packages are not on npm yet.

## Repository metadata

Description, topics and homepage, matching `composer.json`:

```bash
gh repo edit offline-agency/php-image-puzzle \
  --description "Turn images into playable puzzles with GD: jigsaw pieces, eight game modes, manifests, SVG/PDF/ZIP export and a browser player." \
  --homepage "https://github.com/offline-agency/php-image-puzzle#readme" \
  --add-topic php --add-topic puzzle --add-topic jigsaw --add-topic jigsaw-puzzle \
  --add-topic gd --add-topic image-processing --add-topic sliding-puzzle \
  --add-topic nonogram --add-topic memory-game --add-topic web-component
```

## Tests and tooling

| Check | Command |
| --- | --- |
| All mandatory release scenarios (fake GitHub API, temporary Git repositories) | `composer release:test` |
| Same scenarios, versioning, Packagist, artifacts and workflow guarantees | `vendor/bin/pest tests/Release` |
| Artifact build and consumer smoke from a checkout | `php tools/release/build-artifacts.php --sha="$(git rev-parse HEAD)" --version=0.0.0 --out=/tmp/rel && tools/release/smoke-artifacts.sh /tmp/rel 0.0.0` |
| Workflow lint | `actionlint -ignore 'for "concurrency" section'` |

`actionlint` 1.7.12 does not know the `queue` key of `concurrency`, which
GitHub documents
([workflow syntax](https://docs.github.com/actions/reference/workflow-syntax-for-github-actions),
[changelog of 2026-05-07](https://github.blog/changelog/2026-05-07-github-actions-concurrency-groups-now-allow-larger-queues/)).
Only that message is ignored.

The scenarios cover: a valid merge, an unmerged close, merges into `main`,
fork pull requests, the merge, squash and rebase methods, major/minor/patch
classification, contradictory labels, failed, pending, missing, cancelled
and skipped checks, the validation identity, three quick merges with
reversed CI completion, duplicate events, a release API failure after the
tag, tag conflicts, bootstrap, queue overflow, Packagist delays,
malicious text, wrong or partially uploaded artifacts, direct pushes,
exclusions, pull requests before the anchor, a stray higher tag, a dry
run with zero writes, a publisher re-run after success, mismatching and
unexpected assets, line breaks and Markdown in contributor text, breaking
changes in an edited squash message or a direct commit, a pull request
association GitHub has not indexed yet, the API cost of a plan as the
history grows, and a push validation still running when the run validated
the commit itself.
