<?php

declare(strict_types=1);

/*
 * Publishes the planned release: plans again from live data, verifies the
 * downloaded artifacts, then creates (or completes) the annotated tag and
 * the GitHub Release. Runs in the only job with contents: write, from the
 * trusted tooling revision; it installs and executes no package code.
 *   GITHUB_TOKEN=... php tools/release/publish.php --plan=plan.json
 *       --artifacts=DIR --tooling-sha=SHA [--validated]
 * --validated states that this run's validate job passed for the planned
 * commit (the workflow only passes it then).
 */

use ImagePuzzle\Release\ArtifactSet;
use ImagePuzzle\Release\Cli;
use ImagePuzzle\Release\Notes;
use ImagePuzzle\Release\Plan;
use ImagePuzzle\Release\Planner;
use ImagePuzzle\Release\PolicyViolation;
use ImagePuzzle\Release\Publisher;
use ImagePuzzle\Release\ReleaseMetadata;
use ImagePuzzle\Release\Trigger;

require __DIR__ . '/autoload.php';

try {
    $options = Cli::options(Cli::argv());
    $policy = Cli::policy($options['policy'] ?? null);
    $data = json_decode((string) file_get_contents($options['plan'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data) || !is_array($data['release'] ?? null) || !is_string($data['decision'] ?? null)) {
        throw new PolicyViolation('the plan file has no release');
    }
    $planned = new Plan($data['decision'], is_string($data['reason'] ?? null) ? $data['reason'] : '', [], ReleaseMetadata::fromPlan($data['release']));
    $release = $planned->release ?? throw new PolicyViolation('the plan has no release');
    $tooling = Cli::sha($options['tooling-sha'] ?? '', '--tooling-sha');
    $github = Cli::github($policy);
    $planner = new Planner($github, $policy);

    $fresh = $planner->plan(Trigger::dispatch($release->pullRequest), $tooling);
    $artifacts = isset($options['artifacts']) ? ArtifactSet::load($options['artifacts']) : null;
    $done = (new Publisher($github))->publish($planned, $fresh, $artifacts, isset($options['validated']));
    foreach ($done as $line) {
        echo $line, "\n";
    }

    // The next pull request, for the continue job: published right away when
    // validated; a pending one is picked up by its CI completion instead.
    $after = $planner->plan(Trigger::dispatch($release->pullRequest), $tooling);
    $next = in_array($after->decision, [Plan::PUBLISH, Plan::NEEDS_VALIDATION], true) ? $after->release?->pullRequest : null;
    Cli::outputs(['version' => (string) $release->version, 'next_pr' => $next]);
    Cli::summary("### Published {$release->version}\n\n- " . implode("\n- ", array_map(Notes::text(...), $done)) . ($next === null ? '' : "\n\nNext: #{$next}"));
} catch (PolicyViolation|JsonException|RuntimeException $e) {
    fwrite(STDERR, "release publication failed: {$e->getMessage()}\nRe-run this workflow or dispatch it for the same pull request: completed steps are kept and the same version is used.\n");
    exit(1);
}
