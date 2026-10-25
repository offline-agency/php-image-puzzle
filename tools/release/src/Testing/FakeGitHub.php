<?php

declare(strict_types=1);

namespace ImagePuzzle\Release\Testing;

use ImagePuzzle\Release\GitHub\ChangedFile;
use ImagePuzzle\Release\GitHub\GitHub;
use ImagePuzzle\Release\GitHub\PullRequest;
use ImagePuzzle\Release\GitHub\Release;
use ImagePuzzle\Release\GitHub\Tag;
use ImagePuzzle\Release\GitHub\WorkflowRun;

/**
 * Deterministic GitHub for the release scenarios: commits and ancestry come
 * from a real temporary Git repository, pull requests, runs, tags and
 * releases live in memory. Every write is logged, and failures can be
 * injected into the write calls.
 */
final class FakeGitHub implements GitHub
{
    /** @var array<int, PullRequest> */
    public array $pullRequests = [];

    /** @var array<int, list<string>> */
    public array $commitMessages = [];

    /** @var array<int, list<ChangedFile>> */
    public array $files = [];

    /** @var array<string, list<int>> */
    public array $commitPulls = [];

    /** @var array<string, list<WorkflowRun>> */
    public array $runs = [];

    /** @var array<int, string|null> */
    public array $jobs = [];

    /** @var array<string, Tag> */
    public array $tags = [];

    /** @var array<string, Release> */
    public array $releases = [];

    /** @var list<string> */
    public array $writes = [];

    public int $failCreateRelease = 0;

    /** Uploads allowed before the next upload fails (-1: never fail). */
    public int $uploadsBeforeFailure = -1;

    private int $nextId = 1000;

    public function __construct(public readonly GitRepository $git)
    {
    }

    public function pullRequest(int $number): ?PullRequest
    {
        return $this->pullRequests[$number] ?? null;
    }

    public function pullRequestCommitMessages(int $number): array
    {
        return $this->commitMessages[$number] ?? [];
    }

    public function pullRequestFiles(int $number): array
    {
        return $this->files[$number] ?? [];
    }

    public function pullRequestsForCommit(string $sha): array
    {
        return $this->commitPulls[$sha] ?? [];
    }

    public function branchHead(string $branch): string
    {
        return $this->git->revParse($branch);
    }

    public function isAncestor(string $ancestor, string $descendant): bool
    {
        return $this->git->isAncestor($ancestor, $descendant);
    }

    public function commitsBetween(string $base, string $head): array
    {
        return $this->git->revList($base, $head);
    }

    public function workflowRuns(string $workflowPath, string $sha): array
    {
        return $this->runs[$sha] ?? [];
    }

    public function jobConclusion(int $runId, string $jobName): ?string
    {
        return $jobName === 'CI gate' ? ($this->jobs[$runId] ?? null) : null;
    }

    public function tags(): array
    {
        return array_values($this->tags);
    }

    public function createAnnotatedTag(string $name, string $sha, string $message): void
    {
        if (isset($this->tags[$name])) {
            throw new \RuntimeException("Reference already exists: {$name}");
        }
        $this->writes[] = "tag {$name} {$sha}";
        $this->tags[$name] = new Tag($name, $sha, $message);
    }

    public function releaseByTag(string $tag): ?Release
    {
        return $this->releases[$tag] ?? null;
    }

    public function createRelease(string $tag, string $name, string $body): Release
    {
        if ($this->failCreateRelease > 0) {
            $this->failCreateRelease--;
            throw new \RuntimeException('GitHub API POST releases returned HTTP 502');
        }
        if (!isset($this->tags[$tag]) || isset($this->releases[$tag])) {
            throw new \RuntimeException("cannot create release {$tag}");
        }
        $this->writes[] = "release {$tag}";

        return $this->releases[$tag] = new Release($this->nextId++, $tag, $body, []);
    }

    public function uploadAsset(Release $release, string $name, string $path): void
    {
        if ($this->uploadsBeforeFailure === 0) {
            $this->uploadsBeforeFailure = -1;
            throw new \RuntimeException("uploading {$name} failed with HTTP 500");
        }
        if ($this->uploadsBeforeFailure > 0) {
            $this->uploadsBeforeFailure--;
        }
        $current = $this->releases[$release->tagName];
        if (in_array($name, $current->assets, true) || !is_file($path)) {
            throw new \RuntimeException("cannot upload {$name}");
        }
        $this->writes[] = "asset {$release->tagName} {$name}";
        $this->releases[$release->tagName] = new Release($current->id, $current->tagName, $current->body, [...$current->assets, $name]);
    }
}
