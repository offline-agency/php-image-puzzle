<?php

declare(strict_types=1);

/*
 * Static guarantees of the release workflows that a scenario cannot show:
 * the entry contract, a single writing job, pinned actions, and no
 * contributor-controlled text expanded into a shell command.
 */

function workflowText(string $name): string
{
    $text = file_get_contents(dirname(__DIR__, 2) . "/.github/workflows/{$name}");
    if ($text === false) {
        throw new RuntimeException("cannot read {$name}");
    }

    return $text;
}

it('keeps the release entry contract', function (): void {
    $text = workflowText('release-on-merge.yml');

    expect($text)->toStartWith("name: Release\n")
        ->and($text)->toContain("  pull_request_target:\n    types: [closed]\n    branches: [master]\n")
        ->and($text)->toContain("  workflow_run:\n    workflows: [\"CI\"]\n    types: [completed]\n    branches: [master]\n")
        ->and($text)->toContain("      pr_number:\n        description: \"Merged master PR to reconcile or recover\"\n        required: true\n        type: string\n")
        ->and($text)->toContain("      dry_run:\n        description: \"Plan and validate without creating tags or releases\"\n        required: true\n        default: true\n        type: boolean\n")
        ->and($text)->toContain("\npermissions:\n  contents: read\n")
        ->and($text)->toContain("concurrency:\n  group: release-master\n  cancel-in-progress: false\n  queue: max\n");
});

it('gives contents: write to the publish job only', function (): void {
    $text = workflowText('release-on-merge.yml');
    $publish = substr($text, (int) strpos($text, "\n  publish:\n"), (int) strpos($text, "\n  continue:\n") - (int) strpos($text, "\n  publish:\n"));

    expect(substr_count($text, 'contents: write'))->toBe(1)
        ->and($publish)->toContain('contents: write')
        ->and($publish)->not->toContain('composer install')
        ->and($publish)->not->toContain('npm ')
        ->and($text)->not->toContain('persist-credentials: true')
        ->and($text)->not->toContain('secrets.');
});

it('pins every action to a full commit SHA', function (string $workflow): void {
    preg_match_all('/^\s*(?:-\s+)?uses:\s*(\S+)/m', workflowText($workflow), $matches);

    expect($matches[1])->not->toBeEmpty();
    foreach ($matches[1] as $action) {
        expect(str_starts_with($action, './') || preg_match('/^[\w.-]+\/[\w.\/-]+@[0-9a-f]{40}$/', $action) === 1)->toBeTrue("{$action} is not pinned");
    }
})->with(['release-on-merge.yml', 'release-policy.yml', 'ci.yml']);

it('never expands contributor text in the release workflows', function (string $workflow): void {
    $text = workflowText($workflow);

    expect($text)->not->toMatch('/\$\{\{\s*github\.event\.(pull_request\.(title|body|head|labels)|workflow_run\.(head_commit|display_title|head_branch))/')
        ->and($text)->not->toMatch('/\$\{\{\s*github\.head_ref/')
        ->and($text)->not->toContain('eval ');
})->with(['release-on-merge.yml', 'release-policy.yml']);

it('lets the release workflow validate one exact commit through CI', function (): void {
    $ci = workflowText('ci.yml');

    expect($ci)->toContain("  workflow_call:\n    inputs:\n      sha:\n")
        ->and(substr_count($ci, 'ref: ${{ inputs.sha }}'))->toBe(substr_count($ci, 'uses: actions/checkout@'))
        ->and($ci)->toContain('group: ci-${{ github.workflow }}-${{ inputs.sha || github.event.pull_request.number || github.sha }}')
        ->and($ci)->toContain("  gate:\n    name: CI gate\n");
});
