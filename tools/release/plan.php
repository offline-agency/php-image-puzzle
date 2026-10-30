<?php

declare(strict_types=1);

/*
 * Plans the next release from the event and the live repository state,
 * without writing anything to GitHub. Used by the plan job and for dry runs.
 *   GITHUB_TOKEN=... php tools/release/plan.php --event=NAME --payload=event.json
 *       --tooling-sha=SHA [--pr=N] [--out=plan.json]
 * --pr is the workflow_dispatch input (read from the DISPATCH_PR environment
 * variable when absent). Exit code 1 means blocked or invalid.
 */

use ImagePuzzle\Release\Cli;
use ImagePuzzle\Release\Planner;
use ImagePuzzle\Release\PolicyViolation;
use ImagePuzzle\Release\Trigger;

require __DIR__ . '/autoload.php';

try {
    $options = Cli::options(Cli::argv());
    $policy = Cli::policy($options['policy'] ?? null);
    $payload = [];
    if (isset($options['payload'])) {
        $decoded = json_decode((string) file_get_contents($options['payload']), true, 512, JSON_THROW_ON_ERROR);
        $payload = is_array($decoded) ? $decoded : [];
    }
    $dispatch = $options['pr'] ?? (is_string(getenv('DISPATCH_PR')) ? (string) getenv('DISPATCH_PR') : null);
    $trigger = Trigger::fromEvent($options['event'] ?? '', $payload, $dispatch);
    $tooling = Cli::sha($options['tooling-sha'] ?? '', '--tooling-sha');

    $plan = (new Planner(Cli::github($policy), $policy))->plan($trigger, $tooling);
} catch (PolicyViolation|JsonException $e) {
    fwrite(STDERR, "release plan failed: {$e->getMessage()}\n");
    exit(1);
}

$json = json_encode($plan->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
if (isset($options['out'])) {
    file_put_contents($options['out'], $json . "\n");
}
echo $json, "\n";
Cli::outputs([
    'decision' => $plan->decision,
    'pr' => $plan->release?->pullRequest,
    'sha' => $plan->release?->acceptedSha,
    'version' => $plan->release === null ? null : (string) $plan->release->version,
    'resume' => $plan->resume,
]);
Cli::summary(Cli::describe($plan));
exit($plan->exitCode());
