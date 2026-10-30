<?php

declare(strict_types=1);

namespace ImagePuzzle\Release;

use ImagePuzzle\Release\GitHub\WorkflowRun;

/** The event that started a release run, reduced to the fields the planner trusts. */
final readonly class Trigger
{
    public const PULL_REQUEST = 'pull_request_target';
    public const WORKFLOW_RUN = 'workflow_run';
    public const DISPATCH = 'workflow_dispatch';

    private function __construct(
        public string $kind,
        public ?int $pullRequest,
        public ?WorkflowRun $run,
    ) {
    }

    public static function pullRequestClosed(int $number): self
    {
        return new self(self::PULL_REQUEST, $number, null);
    }

    public static function workflowRun(WorkflowRun $run): self
    {
        return new self(self::WORKFLOW_RUN, null, $run);
    }

    public static function dispatch(int $number): self
    {
        return new self(self::DISPATCH, $number, null);
    }

    /**
     * Builds the trigger from GITHUB_EVENT_NAME and the event payload. Only
     * numbers and identifiers are read; the planner fetches everything else
     * again from the API.
     *
     * @param array<mixed> $payload
     */
    public static function fromEvent(string $event, array $payload, ?string $dispatchPullRequest): self
    {
        $number = static function (mixed $value): int {
            $text = is_int($value) ? (string) $value : (is_string($value) ? trim($value) : '');
            if (preg_match('/^[1-9]\d{0,9}$/', $text) !== 1) {
                throw new PolicyViolation('expected a pull request number');
            }

            return (int) $text;
        };
        $string = static fn (array $data, string $key): string => is_string($data[$key] ?? null) ? $data[$key] : '';

        switch ($event) {
            case self::PULL_REQUEST:
                $pr = is_array($payload['pull_request'] ?? null) ? $payload['pull_request'] : [];

                return self::pullRequestClosed($number($pr['number'] ?? null));
            case self::WORKFLOW_RUN:
                $run = is_array($payload['workflow_run'] ?? null) ? $payload['workflow_run'] : [];
                $repository = is_array($run['repository'] ?? null) ? $run['repository'] : [];

                return self::workflowRun(new WorkflowRun(
                    $number($run['id'] ?? null),
                    is_int($run['run_attempt'] ?? null) ? $run['run_attempt'] : 1,
                    $string($run, 'path'),
                    $string($run, 'event'),
                    $string($run, 'head_branch'),
                    $string($run, 'head_sha'),
                    $string($repository, 'full_name'),
                    $string($run, 'status'),
                    is_string($run['conclusion'] ?? null) ? $run['conclusion'] : null,
                ));
            case self::DISPATCH:
                return self::dispatch($number($dispatchPullRequest));
            default:
                throw new PolicyViolation("unsupported event {$event}");
        }
    }
}
