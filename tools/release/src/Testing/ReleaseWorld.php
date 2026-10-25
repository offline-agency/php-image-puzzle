<?php

declare(strict_types=1);

namespace ImagePuzzle\Release\Testing;

use ImagePuzzle\Release\ArtifactSet;
use ImagePuzzle\Release\GitHub\ChangedFile;
use ImagePuzzle\Release\GitHub\PullRequest;
use ImagePuzzle\Release\GitHub\Tag;
use ImagePuzzle\Release\GitHub\WorkflowRun;
use ImagePuzzle\Release\Plan;
use ImagePuzzle\Release\Planner;
use ImagePuzzle\Release\Policy;
use ImagePuzzle\Release\PolicyViolation;
use ImagePuzzle\Release\Publisher;
use ImagePuzzle\Release\Trigger;

/**
 * One scenario's repository: master with the 1.0.2 anchor, a main branch,
 * and helpers that merge pull requests the three ways GitHub does, report
 * CI runs, and run the release workflow's steps (plan, build artifacts,
 * publish, continue with the next pull request) against the fake API.
 */
final class ReleaseWorld
{
    public const REPOSITORY = 'offline-agency/php-image-puzzle';
    public const TOOLING = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public readonly FakeGitHub $github;

    public readonly string $anchor;

    /** @var array<int, array{sha: string, reason: string}> */
    public array $excluded = [];

    private int $runId = 1;

    /** @var list<string> */
    private array $temp = [];

    public function __construct()
    {
        $git = GitRepository::create();
        $git->commit('feat: first release');
        $this->anchor = $git->commit('fix: last 1.x release');
        $git->checkout('main', true);
        $git->checkout('master');
        $this->github = new FakeGitHub($git);
        $this->github->tags['1.0.2'] = new Tag('1.0.2', $this->anchor, null);
    }

    public function policy(): Policy
    {
        return new Policy(self::REPOSITORY, 'master', '1.0.2', $this->anchor, '.github/workflows/ci.yml', 'push', 'CI gate', 'offline-agency/image-puzzle', $this->excluded);
    }

    /**
     * Opens and merges a pull request; returns the accepted commit.
     *
     * @param list<string>      $labels
     * @param list<string>      $commits commit messages of the pull request
     * @param list<ChangedFile> $files
     */
    public function merge(int $number, string $title, string $strategy = 'squash', array $labels = [], string $body = '', array $commits = [], string $base = 'master', bool $fork = false, array $files = []): string
    {
        $git = $this->github->git;
        $commits = $commits === [] ? [$title] : $commits;
        $git->checkout("pr-{$number}", true, $base);
        $own = [];
        foreach ($commits as $message) {
            $own[] = $git->commit($message);
        }
        $git->checkout($base);
        if ($strategy === 'merge') {
            $git->git('merge', '--quiet', '--no-ff', '-m', "Merge pull request #{$number}", "pr-{$number}");
            $accepted = $git->head();
            $mapped = [...$own, $accepted];
        } elseif ($strategy === 'rebase') {
            $mapped = [];
            foreach ($own as $sha) {
                $git->git('cherry-pick', '--allow-empty', $sha);
                $mapped[] = $git->head();
            }
            $accepted = $git->head();
        } else {
            $git->git('merge', '--quiet', '--squash', "pr-{$number}");
            $git->git('commit', '--quiet', '-m', "{$title} (#{$number})");
            $accepted = $git->head();
            $mapped = [$accepted];
        }
        foreach ($mapped as $sha) {
            $this->github->commitPulls[$sha] = [...($this->github->commitPulls[$sha] ?? []), $number];
        }
        $this->github->pullRequests[$number] = new PullRequest($number, 'closed', true, $accepted, $base, self::REPOSITORY, $fork ? 'contributor/php-image-puzzle' : self::REPOSITORY, $title, $body, $labels);
        $this->github->commitMessages[$number] = $commits;
        $this->github->files[$number] = $files;
        $git->checkout('master');

        return $accepted;
    }

    public function closeWithoutMerge(int $number, string $title): void
    {
        $this->github->pullRequests[$number] = new PullRequest($number, 'closed', false, null, 'master', self::REPOSITORY, self::REPOSITORY, $title, '', []);
    }

    public function push(string $message): string
    {
        $this->github->git->checkout('master');

        return $this->github->git->commit($message);
    }

    /** Records a CI run for a commit; returns the run, as a workflow_run event would carry it. */
    public function ci(string $sha, string $conclusion = 'success', string $status = 'completed', ?string $gate = 'success', string $branch = 'master', string $event = 'push'): WorkflowRun
    {
        $run = new WorkflowRun($this->runId++, 1, '.github/workflows/ci.yml', $event, $branch, $sha, self::REPOSITORY, $status, $status === 'completed' ? $conclusion : null);
        $this->github->runs[$sha] = [...($this->github->runs[$sha] ?? []), $run];
        $this->github->jobs[$run->id] = $status === 'completed' ? $gate : null;

        return $run;
    }

    public function plan(Trigger $trigger): Plan
    {
        return (new Planner($this->github, $this->policy()))->plan($trigger, self::TOOLING);
    }

    /**
     * The release workflow for one event: plan; when publishable, build the
     * artifacts for the planned commit and publish; then dispatch itself for
     * the next pull request, as the continue job does, until nothing is left.
     *
     * @return list<Plan> the plan of every run
     */
    public function release(Trigger $trigger, bool $validatedInThisRun = false): array
    {
        $plans = [];
        for ($i = 0; $i < 20; $i++) {
            $plan = $this->plan($trigger);
            $plans[] = $plan;
            $publishable = $plan->decision === Plan::PUBLISH || ($plan->decision === Plan::NEEDS_VALIDATION && $validatedInThisRun);
            if (!$publishable || $plan->release === null) {
                return $plans;
            }
            $artifacts = $this->artifacts($plan->release->acceptedSha, (string) $plan->release->version);
            $fresh = $this->plan($trigger);
            (new Publisher($this->github))->publish($plan, $fresh, $artifacts, $validatedInThisRun);
            $validatedInThisRun = false;
            $trigger = Trigger::dispatch($plan->release->pullRequest);
        }

        throw new PolicyViolation('the release loop did not settle');
    }

    /** A fake build output for one commit, as the read-only build job uploads it. */
    public function artifacts(string $sha, string $version, ?string $builtFrom = null): ArtifactSet
    {
        $dir = sys_get_temp_dir() . '/image-puzzle-artifacts-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o700);
        $this->temp[] = $dir;
        $files = [];
        foreach (["image-puzzle-player-{$version}.iife.js" => "player {$version}", "offline-agency-image-puzzle-player-{$version}.tgz" => "tarball {$version}"] as $name => $contents) {
            file_put_contents("{$dir}/{$name}", $contents);
            $files[] = ['name' => $name, 'sha256' => hash('sha256', $contents), 'size' => strlen($contents)];
        }
        file_put_contents("{$dir}/" . ArtifactSet::MANIFEST, json_encode([
            'format' => ArtifactSet::FORMAT,
            'sha' => $builtFrom ?? $sha,
            'version' => $version,
            'files' => $files,
            'modes' => ['jigsaw', 'sliding'],
            'packages' => ['offline-agency/image-puzzle' => $version, '@offline-agency/image-puzzle-player' => $version],
        ], JSON_THROW_ON_ERROR));

        return ArtifactSet::load($dir);
    }

    /**
     * @return array<string, string> tag name => commit, release tags only
     */
    public function releaseTags(): array
    {
        $tags = [];
        foreach ($this->github->tags as $name => $tag) {
            if ($name !== '1.0.2') {
                $tags[$name] = $tag->commit;
            }
        }
        ksort($tags, SORT_NATURAL);

        return $tags;
    }

    public function cleanUp(): void
    {
        foreach ($this->temp as $dir) {
            $files = glob("{$dir}/*");
            foreach ($files === false ? [] : $files as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
        $this->github->git->remove();
    }
}
