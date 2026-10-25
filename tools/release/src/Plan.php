<?php

declare(strict_types=1);

namespace ImagePuzzle\Release;

/**
 * What a release run should do, with everything a reviewer needs to check
 * it: the plan is printed by every run, and a dry run stops here.
 */
final readonly class Plan
{
    /** Publish a new release (or complete one whose tag exists). */
    public const PUBLISH = 'publish';
    /** The accepted commit has no validation run yet: validate it in this run first. */
    public const NEEDS_VALIDATION = 'needs-validation';
    /** Validation is still running; its completion starts a new run. */
    public const WAIT = 'wait';
    /** Validation failed, was cancelled or skipped: a maintainer must act. */
    public const BLOCKED = 'blocked';
    /** Everything after the anchor is released. */
    public const NOOP = 'noop';
    /** The event is not a release event (closed without merge, other branch, other workflow). */
    public const IGNORED = 'ignored';
    /** The repository state contradicts the policy; nothing is published. */
    public const INVALID = 'invalid';

    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public string $decision,
        public string $reason,
        public array $details = [],
        public ?ReleaseMetadata $release = null,
        public string $notes = '',
        public bool $resume = false,
    ) {
    }

    /** Process exit code: blocked and invalid plans fail the run visibly. */
    public function exitCode(): int
    {
        return in_array($this->decision, [self::BLOCKED, self::INVALID], true) ? 1 : 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'decision' => $this->decision,
            'reason' => $this->reason,
            'resume' => $this->resume,
            ...$this->details,
            'release' => $this->release === null ? null : [
                'pullRequest' => $this->release->pullRequest,
                'acceptedSha' => $this->release->acceptedSha,
                'targetSha' => $this->release->acceptedSha,
                'previousVersion' => $this->release->predecessor === null ? null : (string) $this->release->predecessor,
                'bump' => $this->release->bump->value,
                'bumpReason' => $this->release->reason,
                'version' => (string) $this->release->version,
                'tag' => (string) $this->release->version,
                'toolingSha' => $this->release->toolingSha,
            ],
            'notes' => $this->notes,
        ];
    }
}
