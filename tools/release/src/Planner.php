<?php

declare(strict_types=1);

namespace ImagePuzzle\Release;

use ImagePuzzle\Release\GitHub\GitHub;
use ImagePuzzle\Release\GitHub\PullRequest;
use ImagePuzzle\Release\GitHub\Tag;

/**
 * Decides the next release from the repository state alone. It never
 * writes, so the same code serves dry runs, the plan job and the
 * publisher's final re-check.
 *
 * Whatever the event, the planner reconciles the whole history after the
 * activation anchor: it lists the merged pull requests of the release
 * branch in history order (their accepted merge, squash or rebase commits),
 * finds the first one without a release, and plans that one only. A later
 * pull request is never released before an earlier one, so versions follow
 * the branch history and not the order in which events or CI results
 * arrive; a run for a later pull request simply reports what it waits for.
 */
final class Planner
{
    /** @var array<int, PullRequest|null> */
    private array $pullRequests = [];

    public function __construct(
        private readonly GitHub $github,
        private readonly Policy $policy,
        private readonly Classifier $classifier = new Classifier(),
    ) {
    }

    public function plan(Trigger $trigger, string $toolingSha): Plan
    {
        try {
            return $this->reconcile($trigger, $toolingSha);
        } catch (PolicyViolation $e) {
            return new Plan(Plan::INVALID, $e->getMessage(), ['trigger' => $this->describe($trigger)]);
        }
    }

    private function reconcile(Trigger $trigger, string $toolingSha): Plan
    {
        $base = ['trigger' => $this->describe($trigger), 'branch' => $this->policy->branch, 'anchor' => ['tag' => $this->policy->anchorTag, 'commit' => $this->policy->anchorCommit]];
        $requested = null;
        if ($trigger->kind === Trigger::WORKFLOW_RUN) {
            $run = $trigger->run;
            if ($run === null || $run->path !== $this->policy->validationWorkflow || $run->event !== $this->policy->validationEvent
                || $run->headBranch !== $this->policy->branch || $run->repository !== $this->policy->repository) {
                return new Plan(Plan::IGNORED, 'not a validation run of the release branch', $base);
            }
        } else {
            $requested = $this->pullRequest((int) $trigger->pullRequest);
            $problem = $this->ineligible($requested);
            if ($problem !== null) {
                if ($trigger->kind === Trigger::DISPATCH) {
                    throw new PolicyViolation("pull request #{$trigger->pullRequest} cannot be released: {$problem}");
                }

                return new Plan(Plan::IGNORED, $problem, $base);
            }
        }

        $tags = $this->github->tags();
        $head = $this->github->branchHead($this->policy->branch);
        $this->checkAnchor($tags, $head);
        [$points, $trailing] = $this->releasePoints($head);
        $base['branchHead'] = $head;
        $base['unreleasedDirectCommits'] = $trailing;

        if ($requested !== null && isset($this->policy->excluded[$requested->number])) {
            return new Plan(Plan::IGNORED, "#{$requested->number} is excluded by the release policy: {$this->policy->excluded[$requested->number]['reason']}", $base);
        }
        if ($requested !== null && !$this->contains($points, $requested->number)) {
            if ($requested->mergeCommitSha !== null && $this->github->isAncestor($requested->mergeCommitSha, $this->policy->anchorCommit)) {
                return new Plan(Plan::IGNORED, "#{$requested->number} was merged before the activation anchor {$this->policy->anchorTag}", $base);
            }

            throw new PolicyViolation("#{$requested->number} is merged but its accepted commit is not in the history of {$this->policy->branch} after the anchor");
        }

        $released = $this->releasedByPullRequest($tags);
        $requestedReleased = false;
        foreach ($points as $point) {
            $pr = $point['pr'];
            $entry = $released[$pr->number] ?? null;
            if ($entry === null) {
                // A later requested pull request waits for this one; an earlier one is released already.
                $waitingFor = $requested !== null && !$requestedReleased && $requested->number !== $pr->number ? $requested : null;

                return $this->planNext($point, $waitingFor, $tags, $toolingSha, $base);
            }
            $requestedReleased = $requestedReleased || $pr->number === $requested?->number;
            [$tag, $metadata] = $entry;
            if ($metadata->acceptedSha !== $point['sha'] || $tag->commit !== $point['sha']) {
                throw new PolicyViolation("tag {$tag->name} records #{$pr->number} at {$metadata->acceptedSha}, but the pull request was accepted as {$point['sha']}");
            }
            $published = $this->github->releaseByTag($tag->name);
            // SHA256SUMS is uploaded last, so without it the assets are incomplete.
            if ($published === null || !in_array('SHA256SUMS', $published->assets, true)) {
                $notes = Notes::build($this->policy, $metadata, $pr, $this->github->pullRequestCommitMessages($pr->number), $point['direct']);
                $missing = $published === null ? 'its GitHub Release does not' : 'its release assets are incomplete';

                return new Plan(Plan::PUBLISH, "tag {$tag->name} exists for #{$pr->number} but {$missing}: complete the same release", [...$base, 'pullRequest' => $pr->number, 'acceptedSha' => $point['sha']], $metadata, $notes, true);
            }
        }

        $last = $points === [] ? null : $released[$points[array_key_last($points)]['pr']->number][1];

        return new Plan(Plan::NOOP, $requested !== null && isset($released[$requested->number])
            ? "#{$requested->number} is already released as {$released[$requested->number][1]->version}"
            : 'every pull request merged after the anchor is released', [...$base, 'latest' => $last === null ? $this->policy->anchorTag : (string) $last->version]);
    }

    /**
     * @param array{pr: PullRequest, sha: string, direct: list<string>} $point
     * @param list<Tag>                                                 $tags
     * @param array<string, mixed>                                      $base
     */
    private function planNext(array $point, ?PullRequest $waiting, array $tags, string $toolingSha, array $base): Plan
    {
        $pr = $point['pr'];
        $sha = $point['sha'];
        $details = [...$base, 'pullRequest' => $pr->number, 'acceptedSha' => $sha, 'directCommits' => $point['direct']];
        if ($waiting !== null) {
            $details['waiting'] = ['pullRequest' => $waiting->number, 'releasedAfter' => $pr->number];
        }
        $messages = $this->github->pullRequestCommitMessages($pr->number);
        $classification = $this->classifier->classify($pr, $messages, $this->github->pullRequestFiles($pr->number));
        $predecessor = $this->predecessor($tags, $sha);
        $version = ($predecessor ?? Version::initial())->bump($classification->bump, $classification->explicitMajor);
        $metadata = new ReleaseMetadata($pr->number, $sha, $version, $predecessor, $classification->bump, $classification->reason, $toolingSha);
        $validation = $this->validation($sha);
        $details['validation'] = $validation->toArray($this->policy);
        $details['packagist'] = [
            'package' => $this->policy->composerPackage,
            'version' => (string) $version,
            'reference' => $sha,
            'metadata' => "https://repo.packagist.org/p2/{$this->policy->composerPackage}.json",
            'command' => "php tools/release/verify-packagist.php --version={$version} --sha={$sha}",
        ];
        $notes = Notes::build($this->policy, $metadata, $pr, $messages, $point['direct']);
        $first = $waiting === null ? '' : "#{$pr->number} is the first unreleased pull request and is released before #{$waiting->number}: ";

        return match ($validation->status) {
            Validation::GREEN => new Plan(Plan::PUBLISH, "{$first}release #{$pr->number} as {$version}", $details, $metadata, $notes),
            Validation::PENDING => new Plan(Plan::WAIT, "{$first}validation of {$sha} is still running; its completion starts the release again", $details, $metadata, $notes),
            Validation::MISSING => new Plan(Plan::NEEDS_VALIDATION, "{$first}{$sha} has no validation run; validate this exact commit before publishing", $details, $metadata, $notes),
            default => new Plan(Plan::BLOCKED, "{$first}validation of {$sha} did not pass ({$validation->detail}); re-run it, then dispatch the release for #{$pr->number}", $details, $metadata, $notes),
        };
    }

    private function ineligible(?PullRequest $pr): ?string
    {
        return match (true) {
            $pr === null => 'no such pull request',
            $pr->baseRepository !== $this->policy->repository => "it targets {$pr->baseRepository}",
            !$pr->merged || $pr->state !== 'closed' => 'it was not merged',
            $pr->baseRef !== $this->policy->branch => "it was merged into {$pr->baseRef}, not {$this->policy->branch}",
            $pr->mergeCommitSha === null || preg_match('/^[0-9a-f]{40}$/', $pr->mergeCommitSha) !== 1 => 'the API reports no accepted commit',
            default => null,
        };
    }

    /**
     * @param list<Tag> $tags
     */
    private function checkAnchor(array $tags, string $head): void
    {
        foreach ($tags as $tag) {
            if ($tag->name === $this->policy->anchorTag) {
                if ($tag->commit !== $this->policy->anchorCommit) {
                    throw new PolicyViolation("anchor tag {$tag->name} points at {$tag->commit}, the policy expects {$this->policy->anchorCommit}");
                }
                if (!$this->github->isAncestor($this->policy->anchorCommit, $head)) {
                    throw new PolicyViolation("the anchor {$this->policy->anchorTag} is not in the history of {$this->policy->branch}");
                }

                return;
            }
        }

        throw new PolicyViolation("anchor tag {$this->policy->anchorTag} does not exist");
    }

    /**
     * The merged pull requests after the anchor, in branch history order,
     * each with its accepted commit and the commits pushed without a pull
     * request since the previous one.
     *
     * @return array{0: list<array{pr: PullRequest, sha: string, direct: list<string>}>, 1: list<string>}
     */
    private function releasePoints(string $head): array
    {
        $commits = $this->github->commitsBetween($this->policy->anchorCommit, $head);
        $position = array_flip($commits);
        $points = [];
        $direct = [];
        foreach ($commits as $commit) {
            $merged = [];
            foreach ($this->github->pullRequestsForCommit($commit) as $number) {
                $pr = $this->pullRequest($number);
                if ($pr !== null && $this->ineligible($pr) === null) {
                    $merged[$pr->number] = $pr;
                }
            }
            if (count($merged) > 1) {
                throw new PolicyViolation("commit {$commit} belongs to several merged pull requests (" . implode(', ', array_map(static fn (int $n): string => "#{$n}", array_keys($merged))) . '); resolve the mapping by hand');
            }
            if ($merged === []) {
                $direct[] = $commit;
                continue;
            }
            $pr = array_values($merged)[0];
            $accepted = (string) $pr->mergeCommitSha;
            if (!isset($position[$accepted])) {
                throw new PolicyViolation("#{$pr->number} reports accepted commit {$accepted}, which is not in the history of {$this->policy->branch} after the anchor");
            }
            if ($commit !== $accepted) {
                // A commit of the pull request before its accepted commit (merge or rebase).
                if (!$this->github->isAncestor($commit, $accepted)) {
                    throw new PolicyViolation("commit {$commit} of #{$pr->number} comes after its accepted commit {$accepted}");
                }
                continue;
            }
            if ($points !== [] && !$this->github->isAncestor($points[array_key_last($points)]['sha'], $accepted)) {
                throw new PolicyViolation("cannot order #{$pr->number} after #{$points[array_key_last($points)]['pr']->number}: their commits are not in one line of history");
            }
            $exclusion = $this->policy->excluded[$pr->number] ?? null;
            if ($exclusion !== null) {
                if ($exclusion['sha'] !== $accepted) {
                    throw new PolicyViolation("the exclusion of #{$pr->number} names {$exclusion['sha']}, but it was accepted as {$accepted}");
                }
                $validation = $this->validation($accepted);
                if ($validation->status !== Validation::FAILED) {
                    throw new PolicyViolation("#{$pr->number} is excluded, but its validation is {$validation->status}; only a failed commit can be excluded");
                }
                // Its changes ship with the next release, which lists the commit.
                $direct[] = $accepted;
                continue;
            }
            $points[] = ['pr' => $pr, 'sha' => $accepted, 'direct' => $direct];
            $direct = [];
        }

        return [$points, $direct];
    }

    /**
     * Release tags this process created, by pull request.
     *
     * @param list<Tag> $tags
     *
     * @return array<int, array{0: Tag, 1: ReleaseMetadata}>
     */
    private function releasedByPullRequest(array $tags): array
    {
        $found = [];
        foreach ($tags as $tag) {
            $metadata = ReleaseMetadata::parse($tag->message);
            if ($metadata === null) {
                continue;
            }
            if ((string) $metadata->version !== $tag->name) {
                throw new PolicyViolation("tag {$tag->name} carries release metadata for {$metadata->version}");
            }
            if (isset($found[$metadata->pullRequest])) {
                throw new PolicyViolation("#{$metadata->pullRequest} has two release tags: {$found[$metadata->pullRequest][0]->name} and {$tag->name}");
            }
            $found[$metadata->pullRequest] = [$tag, $metadata];
        }

        return $found;
    }

    /**
     * The highest stable tag in the history of $sha. A higher stable tag
     * elsewhere, or a stable tag already on $sha, is a conflict.
     *
     * @param list<Tag> $tags
     */
    private function predecessor(array $tags, string $sha): ?Version
    {
        $lineage = null;
        $outside = [];
        foreach ($tags as $tag) {
            $version = Version::parse($tag->name);
            if ($version === null) {
                continue;
            }
            if ($tag->commit === $sha) {
                throw new PolicyViolation("tag {$tag->name} already points at {$sha} without being this pull request's release");
            }
            if ($this->github->isAncestor($tag->commit, $sha)) {
                $lineage = $lineage === null || $version->compare($lineage) > 0 ? $version : $lineage;
            } else {
                $outside[] = [$tag, $version];
            }
        }
        foreach ($outside as [$tag, $version]) {
            if ($lineage === null || $version->compare($lineage) > 0) {
                throw new PolicyViolation("stable tag {$tag->name} at {$tag->commit} is higher than the release history of {$sha}; resolve it before releasing");
            }
        }

        return $lineage;
    }

    private function validation(string $sha): Validation
    {
        $runs = array_values(array_filter(
            $this->github->workflowRuns($this->policy->validationWorkflow, $sha),
            fn ($run): bool => $run->headSha === $sha && $run->path === $this->policy->validationWorkflow && $run->event === $this->policy->validationEvent
                && $run->headBranch === $this->policy->branch && $run->repository === $this->policy->repository,
        ));
        if ($runs === []) {
            return new Validation(Validation::MISSING, "no {$this->policy->validationEvent} run of {$this->policy->validationWorkflow} on {$this->policy->branch}");
        }
        $pending = null;
        $failures = [];
        foreach ($runs as $run) {
            if ($run->status !== 'completed') {
                $pending ??= $run;
                continue;
            }
            $gate = $this->github->jobConclusion($run->id, $this->policy->validationJob);
            if ($run->conclusion === 'success' && $gate === 'success') {
                return new Validation(Validation::GREEN, "run {$run->id} attempt {$run->attempt}: {$this->policy->validationJob} succeeded", $run->id, $run->attempt);
            }
            $failures[] = "run {$run->id}: " . ($run->conclusion ?? 'no conclusion') . ", {$this->policy->validationJob} " . ($gate ?? 'absent');
        }
        if ($pending !== null) {
            return new Validation(Validation::PENDING, "run {$pending->id} is {$pending->status}", $pending->id, $pending->attempt);
        }

        return new Validation(Validation::FAILED, implode('; ', $failures));
    }

    /**
     * @param list<array{pr: PullRequest, sha: string, direct: list<string>}> $points
     */
    private function contains(array $points, int $number): bool
    {
        foreach ($points as $point) {
            if ($point['pr']->number === $number) {
                return true;
            }
        }

        return false;
    }

    private function pullRequest(int $number): ?PullRequest
    {
        if (!array_key_exists($number, $this->pullRequests)) {
            $this->pullRequests[$number] = $this->github->pullRequest($number);
        }

        return $this->pullRequests[$number];
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(Trigger $trigger): array
    {
        return ['event' => $trigger->kind, 'pullRequest' => $trigger->pullRequest, 'run' => $trigger->run?->id];
    }
}
