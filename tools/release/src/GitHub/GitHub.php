<?php

declare(strict_types=1);

namespace ImagePuzzle\Release\GitHub;

/**
 * The GitHub operations the release process needs. HttpGitHub talks to the
 * REST API; the scenario tests use a fake backed by a temporary Git
 * repository. Only createAnnotatedTag, createRelease and uploadAsset write.
 */
interface GitHub
{
    public function pullRequest(int $number): ?PullRequest;

    /**
     * @return list<string> full commit messages of the pull request
     */
    public function pullRequestCommitMessages(int $number): array;

    /**
     * @return list<ChangedFile>
     */
    public function pullRequestFiles(int $number): array;

    /**
     * @return list<int> numbers of the pull requests associated with a commit
     */
    public function pullRequestsForCommit(string $sha): array;

    public function branchHead(string $branch): string;

    /** Whether $ancestor is $descendant or one of its ancestors. */
    public function isAncestor(string $ancestor, string $descendant): bool;

    /**
     * @return list<string> commits reachable from $head but not from $base, parents first
     */
    public function commitsBetween(string $base, string $head): array;

    /**
     * @return list<WorkflowRun> runs of one workflow file for one commit
     */
    public function workflowRuns(string $workflowPath, string $sha): array;

    /** Conclusion of a job (by name) in the latest attempt of a run; null while it runs or when absent. */
    public function jobConclusion(int $runId, string $jobName): ?string;

    /**
     * @return list<Tag> every tag of the repository
     */
    public function tags(): array;

    public function createAnnotatedTag(string $name, string $sha, string $message): void;

    public function releaseByTag(string $tag): ?Release;

    public function createRelease(string $tag, string $name, string $body): Release;

    public function uploadAsset(Release $release, string $name, string $path): void;
}
