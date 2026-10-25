<?php

declare(strict_types=1);

namespace ImagePuzzle\Release\GitHub;

/** One GitHub Actions workflow run (its latest attempt). */
final readonly class WorkflowRun
{
    public function __construct(
        public int $id,
        public int $attempt,
        public string $path,
        public string $event,
        public string $headBranch,
        public string $headSha,
        public string $repository,
        public string $status,
        public ?string $conclusion,
    ) {
    }
}
