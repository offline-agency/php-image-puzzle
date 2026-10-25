<?php

declare(strict_types=1);

namespace ImagePuzzle\Release;

/**
 * The required validation of one accepted commit: the configured workflow
 * file, run for a push to the release branch of this repository at exactly
 * that SHA, with its gate job successful.
 */
final readonly class Validation
{
    public const GREEN = 'green';
    public const PENDING = 'pending';
    public const MISSING = 'missing';
    public const FAILED = 'failed';

    public function __construct(
        public string $status,
        public string $detail,
        public ?int $runId = null,
        public ?int $attempt = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Policy $policy): array
    {
        return [
            'workflow' => $policy->validationWorkflow,
            'event' => $policy->validationEvent,
            'branch' => $policy->branch,
            'job' => $policy->validationJob,
            'status' => $this->status,
            'detail' => $this->detail,
            'runId' => $this->runId,
            'attempt' => $this->attempt,
        ];
    }
}
