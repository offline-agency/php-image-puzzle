<?php

declare(strict_types=1);

namespace ImagePuzzle\Release\Testing;

use ImagePuzzle\Release\Classifier;
use ImagePuzzle\Release\GitHub\ChangedFile;
use ImagePuzzle\Release\GitHub\PullRequest;
use ImagePuzzle\Release\GitHub\Tag;
use ImagePuzzle\Release\Http\Http;
use ImagePuzzle\Release\Http\Response;
use ImagePuzzle\Release\PackagistVerifier;
use ImagePuzzle\Release\Plan;
use ImagePuzzle\Release\PolicyViolation;
use ImagePuzzle\Release\Publisher;
use ImagePuzzle\Release\ReleaseMetadata;
use ImagePuzzle\Release\Trigger;

/**
 * The mandatory release scenarios. Each one builds a fresh repository with
 * the 1.0.2 anchor, drives the planner and publisher through the fake API,
 * and returns a line of evidence; a failed expectation throws.
 */
final class Scenarios
{
    /**
     * @return array<string, \Closure(ReleaseWorld): string>
     */
    public static function all(): array
    {
        return [
            'valid merged PR with green exact-SHA checks' => static function (ReleaseWorld $w): string {
                $sha = $w->merge(10, 'fix: keep piece order stable');
                $w->ci($sha);
                $plans = $w->release(Trigger::pullRequestClosed(10));
                self::that($w->releaseTags() === ['1.0.3' => $sha], 'one tag 1.0.3 at the accepted commit');
                self::that(isset($w->github->releases['1.0.3']) && count($w->github->writes) === 5, 'one tag, one release, two assets and SHA256SUMS');
                self::that(end($plans) !== false && end($plans)->decision === Plan::NOOP, 'the continue step finds nothing left');

                return 'tag 1.0.3 at ' . substr($sha, 0, 8) . ', writes: ' . implode('; ', $w->github->writes);
            },
            'PR closed without merge' => static function (ReleaseWorld $w): string {
                $w->closeWithoutMerge(11, 'feat: abandoned');
                $plan = $w->plan(Trigger::pullRequestClosed(11));
                self::that($plan->decision === Plan::IGNORED && $w->github->writes === [], 'ignored without writes');
                $dispatch = $w->plan(Trigger::dispatch(11));
                self::that($dispatch->decision === Plan::INVALID, 'a dispatch for it is refused');

                return "{$plan->decision}: {$plan->reason}; dispatch: {$dispatch->decision}";
            },
            'merge into main or another branch' => static function (ReleaseWorld $w): string {
                $sha = $w->merge(12, 'fix: on main', base: 'main');
                $run = $w->ci($sha, branch: 'main');
                $closed = $w->plan(Trigger::pullRequestClosed(12));
                $ci = $w->plan(Trigger::workflowRun($run));
                self::that($closed->decision === Plan::IGNORED && $ci->decision === Plan::IGNORED && $w->github->writes === [], 'no publication from main');

                return "pull_request_target: {$closed->reason}; workflow_run: {$ci->reason}";
            },
            'merged fork PR' => static function (ReleaseWorld $w): string {
                $sha = $w->merge(13, 'fix: contributed from a fork', fork: true);
                $w->ci($sha);
                $w->release(Trigger::pullRequestClosed(13));
                self::that($w->releaseTags() === ['1.0.3' => $sha], 'released like any merged PR, from the accepted commit on master');

                return 'fork head contributor/php-image-puzzle released as 1.0.3 at ' . substr($sha, 0, 8);
            },
            'merge, squash and rebase methods' => static function (ReleaseWorld $w): string {
                $merge = $w->merge(14, 'fix: merge commit', 'merge', commits: ['fix: one', 'fix: two']);
                $w->ci($merge);
                $w->release(Trigger::pullRequestClosed(14));
                $squash = $w->merge(15, 'fix: squashed', 'squash', commits: ['wip', 'fix: squashed']);
                $w->ci($squash);
                $w->release(Trigger::pullRequestClosed(15));
                $rebase = $w->merge(16, 'fix: rebased', 'rebase', commits: ['fix: first', 'fix: second']);
                $w->ci($rebase);
                $w->release(Trigger::pullRequestClosed(16));
                self::that($w->releaseTags() === ['1.0.3' => $merge, '1.0.4' => $squash, '1.0.5' => $rebase], 'each tag at the accepted commit');
                $first = $w->github->pullRequest(16)?->mergeCommitSha;
                self::that($first === $rebase && $w->github->git->revParse("{$rebase}^") !== $w->anchor, 'rebase: the last rebased commit, not an earlier one');

                return 'merge ' . substr($merge, 0, 8) . ' -> 1.0.3, squash ' . substr($squash, 0, 8) . ' -> 1.0.4, rebase ' . substr($rebase, 0, 8) . ' -> 1.0.5';
            },
            'breaking, feature and maintenance PRs' => static function (ReleaseWorld $w): string {
                $docs = $w->merge(17, 'docs: clarify limits');
                $w->ci($docs);
                $w->release(Trigger::pullRequestClosed(17));
                $feat = $w->merge(18, 'feat: add a mode');
                $w->ci($feat);
                $w->release(Trigger::pullRequestClosed(18));
                $breaking = $w->merge(19, 'refactor: new manifest', body: "BREAKING CHANGE: manifest v3\n");
                $w->ci($breaking);
                $w->release(Trigger::pullRequestClosed(19));
                $deps = $w->merge(20, 'build(deps): bump a dev tool');
                $w->ci($deps);
                $w->release(Trigger::pullRequestClosed(20));
                self::that(array_keys($w->releaseTags()) === ['1.0.3', '1.1.0', '2.0.0', '2.0.1'], 'patch, minor, major, patch');

                return 'docs 1.0.3, feat 1.1.0, BREAKING CHANGE 2.0.0, dependency 2.0.1';
            },
            'contradictory bump metadata' => static function (ReleaseWorld $w): string {
                $sha = $w->merge(21, 'feat!: drop PHP 8.4', labels: ['release:patch']);
                $w->ci($sha);
                $plan = $w->plan(Trigger::pullRequestClosed(21));
                self::that($plan->decision === Plan::INVALID && $w->github->writes === [], 'refused at publication');
                $premerge = self::premerge(new PullRequest(22, 'open', false, null, 'master', ReleaseWorld::REPOSITORY, ReleaseWorld::REPOSITORY, 'fix: x', '', ['release:minor', 'release:major']), []);
                $schema = self::premerge(new PullRequest(23, 'open', false, null, 'master', ReleaseWorld::REPOSITORY, ReleaseWorld::REPOSITORY, 'fix: schema typo', '', []), [new ChangedFile('docs/schemas/v2/manifest.schema.json', 'modified')]);
                self::that($premerge !== null && $schema !== null, 'rejected before merge');

                return "publication: {$plan->reason}; premerge: {$premerge}; {$schema}";
            },
            'failed, pending, missing, cancelled or skipped checks' => static function (ReleaseWorld $w): string {
                $sha = $w->merge(24, 'fix: needs CI');
                $missing = $w->plan(Trigger::pullRequestClosed(24));
                $w->ci($sha, status: 'in_progress');
                $pending = $w->plan(Trigger::pullRequestClosed(24));
                $w->github->runs[$sha] = [];
                $w->ci($sha, 'failure', gate: 'failure');
                $failed = $w->plan(Trigger::pullRequestClosed(24));
                $w->github->runs[$sha] = [];
                $w->ci($sha, 'cancelled', gate: 'cancelled');
                $cancelled = $w->plan(Trigger::pullRequestClosed(24));
                $w->github->runs[$sha] = [];
                $w->ci($sha, 'success', gate: 'skipped');
                $skipped = $w->plan(Trigger::pullRequestClosed(24));
                $w->github->runs[$sha] = [];
                $w->ci($sha, 'success', gate: null);
                $absentGate = $w->plan(Trigger::pullRequestClosed(24));
                self::that([$missing->decision, $pending->decision, $failed->decision, $cancelled->decision, $skipped->decision, $absentGate->decision] === [Plan::NEEDS_VALIDATION, Plan::WAIT, Plan::BLOCKED, Plan::BLOCKED, Plan::BLOCKED, Plan::BLOCKED], 'no publication');
                self::that($w->github->writes === [], 'zero writes');
                $rerun = $w->ci($sha);
                $w->release(Trigger::workflowRun($rerun));
                self::that($w->releaseTags() === ['1.0.3' => $sha], 'a green re-run resumes the release');

                return "missing: {$missing->decision}, pending: {$pending->decision}, failed/cancelled/skipped/no gate: blocked; re-run: 1.0.3";
            },
            'validation identity is bound to the workflow, event, branch and SHA' => static function (ReleaseWorld $w): string {
                $sha = $w->merge(25, 'fix: identity');
                $w->ci($sha, event: 'pull_request');
                $w->ci($sha, branch: 'main');
                $forged = $w->ci($sha);
                $w->github->runs[$sha] = [...array_slice($w->github->runs[$sha], 0, 2), new \ImagePuzzle\Release\GitHub\WorkflowRun($forged->id, 1, '.github/workflows/other.yml', 'push', 'master', $sha, ReleaseWorld::REPOSITORY, 'completed', 'success')];
                $plan = $w->plan(Trigger::pullRequestClosed(25));
                self::that($plan->decision === Plan::NEEDS_VALIDATION, 'pull_request runs, other branches and other workflows do not count');
                $other = $w->plan(Trigger::workflowRun($forged));
                self::that($other->decision === Plan::PUBLISH || $other->decision === Plan::NEEDS_VALIDATION, 'the event only re-enters reconciliation');

                return "with only a PR-event run, a main run and an other.yml run: {$plan->decision}";
            },
            'three quick merges with reversed CI completion' => static function (ReleaseWorld $w): string {
                $a = $w->merge(26, 'fix: a');
                $b = $w->merge(27, 'feat: b');
                $c = $w->merge(28, 'fix: c');
                foreach ([$a, $b, $c] as $sha) {
                    $w->ci($sha, status: 'in_progress');
                }
                $w->github->runs[$c] = [];
                $runC = $w->ci($c);
                $first = $w->release(Trigger::workflowRun($runC));
                self::that($w->releaseTags() === [] && $first[0]->decision === Plan::WAIT, 'C is not released before A');
                $w->github->runs[$b] = [];
                $runB = $w->ci($b);
                $w->release(Trigger::pullRequestClosed(27));
                self::that($w->releaseTags() === [], 'B waits for A as well');
                $w->github->runs[$a] = [];
                $runA = $w->ci($a);
                $w->release(Trigger::workflowRun($runA));
                self::that($w->releaseTags() === ['1.0.3' => $a, '1.1.0' => $b, '1.1.1' => $c], 'A, B, C in history order');

                return 'events C, B, A -> 1.0.3 (A), 1.1.0 (B), 1.1.1 (C); run ids ' . $runC->id . ', ' . $runB->id . ', ' . $runA->id;
            },
            'successful rerun or duplicate event' => static function (ReleaseWorld $w): string {
                $sha = $w->merge(29, 'fix: once');
                $run = $w->ci($sha);
                $w->release(Trigger::pullRequestClosed(29));
                $writes = count($w->github->writes);
                $again = [$w->plan(Trigger::pullRequestClosed(29)), $w->plan(Trigger::workflowRun($run)), $w->plan(Trigger::dispatch(29))];
                $w->release(Trigger::workflowRun($run));
                self::that(array_map(static fn (Plan $p): string => $p->decision, $again) === [Plan::NOOP, Plan::NOOP, Plan::NOOP], 'every repeat is a no-op');
                self::that(count($w->github->writes) === $writes, 'no duplicate tag or release');

                return $again[0]->reason;
            },
            'tag created, release API fails' => static function (ReleaseWorld $w): string {
                $sha = $w->merge(30, 'fix: flaky API');
                $w->ci($sha);
                $w->github->failCreateRelease = 1;
                $error = '';
                try {
                    $w->release(Trigger::pullRequestClosed(30));
                } catch (\RuntimeException $e) {
                    $error = $e->getMessage();
                }
                self::that(isset($w->github->tags['1.0.3']) && !isset($w->github->releases['1.0.3']), 'tag exists, release missing');
                $resume = $w->plan(Trigger::dispatch(30));
                self::that($resume->decision === Plan::PUBLISH && $resume->resume && (string) $resume->release?->version === '1.0.3', 'the same allocation is resumed');
                $w->release(Trigger::dispatch(30));
                self::that(isset($w->github->releases['1.0.3']) && $w->releaseTags() === ['1.0.3' => $sha], 'release completed, no 1.0.4');

                return "first run: {$error}; resumed as 1.0.3";
            },
            'tag conflicts with another SHA' => static function (ReleaseWorld $w): string {
                $sha = $w->merge(31, 'fix: conflict');
                $w->ci($sha);
                // 1. The planned version already exists on a commit outside the history.
                $w->github->git->checkout('stray', true, $w->anchor);
                $stray = $w->github->git->commit('stray');
                $w->github->git->checkout('master');
                $w->github->tags['1.0.3'] = new Tag('1.0.3', $stray, null);
                $outside = $w->plan(Trigger::pullRequestClosed(31));
                self::that($outside->decision === Plan::INVALID && $w->github->writes === [], 'refused at planning');
                // 2. The tag appears at another commit between planning and publishing.
                unset($w->github->tags['1.0.3']);
                $plan = $w->plan(Trigger::pullRequestClosed(31));
                $w->github->tags['1.0.3'] = new Tag('1.0.3', $stray, null);
                $race = self::failure(static fn () => (new Publisher($w->github))->publish($plan, $plan, $w->artifacts($sha, '1.0.3'), false));
                self::that($race !== '' && $w->github->tags['1.0.3']->commit === $stray && $w->github->writes === [], 'publisher fails, tag untouched');
                // 3. Release metadata for this pull request recorded at another commit.
                $version = \ImagePuzzle\Release\Version::parse('1.0.3') ?? throw new \LogicException();
                $w->github->tags['1.0.3'] = new Tag('1.0.3', $stray, (new ReleaseMetadata(31, $stray, $version, null, \ImagePuzzle\Release\Bump::Patch, 'x', ReleaseWorld::TOOLING))->tagMessage());
                $recorded = $w->plan(Trigger::pullRequestClosed(31));
                self::that($recorded->decision === Plan::INVALID && $w->github->writes === [], 'metadata at another commit fails');

                return "planning: {$outside->reason} / publishing: {$race} / metadata: {$recorded->reason}";
            },
            'workflow installation: idempotent bootstrap' => static function (ReleaseWorld $w): string {
                $sha = $w->merge(32, 'ci: install the release workflow');
                $w->ci($sha);
                // The installing merge produced no release event: a maintainer dispatches it.
                $dry = $w->plan(Trigger::dispatch(32));
                self::that($dry->decision === Plan::PUBLISH && $w->github->writes === [], 'dry run plans 1.0.3 and writes nothing');
                $w->release(Trigger::dispatch(32));
                $w->release(Trigger::dispatch(32));
                self::that($w->releaseTags() === ['1.0.3' => $sha], 'bootstrap twice, released once');
                $fabricated = $w->plan(Trigger::dispatch(999));
                self::that($fabricated->decision === Plan::INVALID, 'unknown PR numbers are refused');

                return "dispatch #32 -> 1.0.3 once; #999: {$fabricated->reason}";
            },
            'queue overflow or missed events' => static function (ReleaseWorld $w): string {
                $a = $w->merge(33, 'fix: lost event a');
                $b = $w->merge(34, 'fix: lost event b');
                $c = $w->merge(35, 'fix: delivered');
                $w->ci($a);
                $w->ci($b);
                $runC = $w->ci($c);
                $w->release(Trigger::workflowRun($runC));
                self::that($w->releaseTags() === ['1.0.3' => $a, '1.0.4' => $b, '1.0.5' => $c], 'backlog after the anchor reconciled in order');

                return 'one delivered event released #33, #34, #35';
            },
            'Packagist delayed or unavailable' => static function (ReleaseWorld $w): string {
                $sha = $w->merge(36, 'fix: indexed later');
                $w->ci($sha);
                $w->release(Trigger::pullRequestClosed(36));
                $writes = count($w->github->writes);
                $metadata = json_encode(['minified' => 'composer/2.0', 'packages' => ['offline-agency/image-puzzle' => [
                    ['name' => 'offline-agency/image-puzzle', 'version' => '1.0.3', 'source' => ['type' => 'git', 'reference' => $sha], 'dist' => ['type' => 'zip', 'reference' => $sha]],
                    ['version' => '1.0.2', 'source' => ['type' => 'git', 'reference' => $w->anchor], 'dist' => ['type' => 'zip', 'reference' => $w->anchor]],
                ]]], JSON_THROW_ON_ERROR);
                $sleeps = [];
                $delayed = new PackagistVerifier(self::http([404, 200, 200], ['', '{"packages":{}}', $metadata]), attempts: 5, sleep: static function (int $s) use (&$sleeps): void {
                    $sleeps[] = $s;
                });
                $found = $delayed->waitFor('offline-agency/image-puzzle', '1.0.3', $sha);
                $down = (new PackagistVerifier(self::http([503, 503, 503], ['', '', '']), attempts: 3, sleep: static function (int $s): void {
                }))->waitFor('offline-agency/image-puzzle', '1.0.3', $sha);
                self::that($found['found'] && $found['attempts'] === 3 && $sleeps === [15, 30], 'found on the third poll with backoff');
                self::that(!$down['found'], 'unavailable is reported');
                $w->release(Trigger::dispatch(36));
                self::that(count($w->github->writes) === $writes, 'the release is kept and not repeated');

                return "delayed: {$found['detail']} after {$found['attempts']} polls; unavailable: {$down['detail']}";
            },
            'malicious title, body and label' => static function (ReleaseWorld $w): string {
                $marker = sys_get_temp_dir() . '/image-puzzle-pwned-' . bin2hex(random_bytes(4));
                $title = 'fix: $(touch ' . $marker . ') `touch ' . $marker . '` <img src=x onerror=alert(1)> @everyone';
                $sha = $w->merge(37, $title, body: "## Migration\n<script>alert(1)</script>\n\$(rm -rf /)", labels: ['release:major']);
                $w->ci($sha);
                $w->release(Trigger::pullRequestClosed(37));
                $body = $w->github->releases['2.0.0']->body ?? '';
                self::that(!file_exists($marker), 'nothing executed');
                self::that(!str_contains($body, '<script>') && !str_contains($body, '<img') && !str_contains($body, '@everyone') && str_contains($body, '&lt;script&gt;'), 'text escaped in the notes');
                $bad = $w->merge(38, 'fix: label', labels: ['release:patch; rm -rf /']);
                $w->ci($bad);
                $plan = $w->plan(Trigger::pullRequestClosed(38));
                self::that($plan->decision === Plan::INVALID, 'unknown label refused as data');

                return "released 2.0.0 with escaped notes; label: {$plan->reason}";
            },
            'artifacts: wrong SHA, bad checksum, interrupted upload' => static function (ReleaseWorld $w): string {
                $sha = $w->merge(39, 'fix: artifacts');
                $w->ci($sha);
                $plan = $w->plan(Trigger::pullRequestClosed(39));
                $publisher = new Publisher($w->github);
                $wrong = self::failure(static fn () => $publisher->publish($plan, $plan, $w->artifacts($sha, '1.0.3', $w->anchor), false));
                $set = $w->artifacts($sha, '1.0.3');
                file_put_contents($set->path($set->files[0]['name']), 'tampered');
                $tampered = self::failure(static fn () => $publisher->publish($plan, $plan, $set, false));
                self::that($wrong !== '' && $tampered !== '' && $w->github->writes === [], 'refused before any write');
                $w->github->uploadsBeforeFailure = 1;
                $interrupted = self::failure(static fn () => $publisher->publish($plan, $w->plan(Trigger::pullRequestClosed(39)), $w->artifacts($sha, '1.0.3'), false));
                $assets = $w->github->releases['1.0.3']->assets;
                $resume = $w->plan(Trigger::dispatch(39));
                self::that($resume->decision === Plan::PUBLISH && $resume->resume, 'incomplete assets are planned as a resume');
                $w->release(Trigger::dispatch(39));
                self::that(count($assets) === 1 && count($w->github->releases['1.0.3']->assets) === 3 && $w->releaseTags() === ['1.0.3' => $sha], 'the rerun uploads only what is missing');
                $writes = count($w->github->writes);
                self::that($w->plan(Trigger::dispatch(39))->decision === Plan::NOOP && count($w->github->writes) === $writes, 'complete afterwards');

                return "wrong SHA: {$wrong}; checksum: {$tampered}; interrupted: {$interrupted}; rerun: {$resume->reason}";
            },
            'direct push between pull requests' => static function (ReleaseWorld $w): string {
                $direct = $w->push('chore: hotfix pushed directly');
                $sha = $w->merge(40, 'fix: after the push');
                $w->ci($sha);
                $w->release(Trigger::pullRequestClosed(40));
                $body = $w->github->releases['1.0.3']->body ?? '';
                self::that(str_contains($body, substr($direct, 0, 12)), 'listed in the next release');

                return 'direct commit ' . substr($direct, 0, 8) . ' listed in 1.0.3';
            },
            'reviewed exclusion of a commit that failed for good' => static function (ReleaseWorld $w): string {
                $broken = $w->merge(41, 'fix: broke the build');
                $w->ci($broken, 'failure', gate: 'failure');
                $fixed = $w->merge(42, 'fix: repair the build');
                $w->ci($fixed);
                $blocked = $w->plan(Trigger::pullRequestClosed(42));
                $w->excluded = [41 => ['sha' => $broken, 'reason' => 'CI failed; repaired by #42']];
                $w->release(Trigger::pullRequestClosed(42));
                self::that($blocked->decision === Plan::BLOCKED && $w->releaseTags() === ['1.0.3' => $fixed], 'blocked until the exclusion is reviewed, then released');

                return "before: {$blocked->decision}; after the policy exclusion: 1.0.3 at #42";
            },
            'PR merged before the activation anchor' => static function (ReleaseWorld $w): string {
                $w->github->pullRequests[1] = new PullRequest(1, 'closed', true, $w->anchor, 'master', ReleaseWorld::REPOSITORY, ReleaseWorld::REPOSITORY, 'feat: old', '', []);
                $plan = $w->plan(Trigger::dispatch(1));
                self::that($plan->decision === Plan::IGNORED && $w->github->writes === [], 'history is not released retroactively');

                return $plan->reason;
            },
            'higher stable tag outside the history' => static function (ReleaseWorld $w): string {
                $w->github->git->checkout('side', true, $w->anchor);
                $side = $w->github->git->commit('side');
                $w->github->git->checkout('master');
                $w->github->tags['3.0.0'] = new Tag('3.0.0', $side, null);
                $w->github->tags['v9.0.0'] = new Tag('v9.0.0', $side, null);
                $sha = $w->merge(43, 'fix: after a stray tag');
                $w->ci($sha);
                $plan = $w->plan(Trigger::pullRequestClosed(43));
                self::that($plan->decision === Plan::INVALID && str_contains($plan->reason, '3.0.0'), 'conflict reported');

                return $plan->reason;
            },
            'dry run shows the whole plan with zero writes' => static function (ReleaseWorld $w): string {
                $sha = $w->merge(44, 'feat: planned');
                $w->ci($sha);
                $plan = $w->plan(Trigger::dispatch(44))->toArray();
                $release = is_array($plan['release'] ?? null) ? $plan['release'] : [];
                foreach (['pullRequest', 'acceptedSha', 'previousVersion', 'bump', 'bumpReason', 'tag', 'targetSha'] as $key) {
                    self::that(array_key_exists($key, $release), "release.{$key}");
                }
                foreach (['branch', 'validation', 'packagist', 'notes'] as $key) {
                    self::that(array_key_exists($key, $plan), $key);
                }
                self::that($w->github->writes === [], 'zero writes');

                return 'PR #44, branch master, ' . substr($sha, 0, 8) . ', 1.0.2 -> 1.1.0 (minor), validation green, Packagist plan included';
            },
        ];
    }

    private static function that(bool $condition, string $expectation): void
    {
        if (!$condition) {
            throw new PolicyViolation("expected: {$expectation}");
        }
    }

    /**
     * @param list<ChangedFile> $files
     */
    private static function premerge(PullRequest $pr, array $files): ?string
    {
        try {
            (new Classifier())->classify($pr, [], $files);

            return null;
        } catch (PolicyViolation $e) {
            return $e->getMessage();
        }
    }

    /**
     * @param \Closure(): mixed $action
     */
    private static function failure(\Closure $action): string
    {
        try {
            $action();
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }

        return '';
    }

    /**
     * @param list<int>    $statuses
     * @param list<string> $bodies
     */
    private static function http(array $statuses, array $bodies): Http
    {
        return new class ($statuses, $bodies) implements Http {
            /**
             * @param list<int>    $statuses
             * @param list<string> $bodies
             */
            public function __construct(private array $statuses, private array $bodies)
            {
            }

            public function request(string $method, string $url, array $headers = [], ?string $body = null): Response
            {
                return new Response(array_shift($this->statuses) ?? 404, array_shift($this->bodies) ?? '');
            }
        };
    }
}
