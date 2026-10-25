<?php

declare(strict_types=1);

namespace ImagePuzzle\Release\GitHub;

/**
 * A pull request as the API reports it. Title, body and labels are written
 * by contributors: they are data and never reach a shell or an evaluator.
 */
final readonly class PullRequest
{
    /**
     * @param list<string> $labels
     */
    public function __construct(
        public int $number,
        public string $state,
        public bool $merged,
        public ?string $mergeCommitSha,
        public string $baseRef,
        public string $baseRepository,
        public string $headRepository,
        public string $title,
        public string $body,
        public array $labels,
    ) {
    }
}
