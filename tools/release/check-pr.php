<?php

declare(strict_types=1);

/*
 * Pre-merge check of a pull request's release classification: fails on
 * contradictory or unknown release labels, a label lower than the
 * Conventional Commit metadata, or contract changes without a label.
 * Read-only; runs on pull_request events, also for forks.
 *   GITHUB_TOKEN=... php tools/release/check-pr.php --payload=event.json
 */

use ImagePuzzle\Release\Classifier;
use ImagePuzzle\Release\Cli;
use ImagePuzzle\Release\GitHub\PullRequest;
use ImagePuzzle\Release\Notes;
use ImagePuzzle\Release\PolicyViolation;

require __DIR__ . '/autoload.php';

try {
    $options = Cli::options(Cli::argv());
    $policy = Cli::policy($options['policy'] ?? null);
    $event = json_decode((string) file_get_contents($options['payload'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
    $data = is_array($event) && is_array($event['pull_request'] ?? null) ? $event['pull_request'] : throw new PolicyViolation('not a pull_request event');
    $labels = [];
    foreach (is_array($data['labels'] ?? null) ? $data['labels'] : [] as $label) {
        if (is_array($label) && is_string($label['name'] ?? null)) {
            $labels[] = $label['name'];
        }
    }
    $number = is_int($data['number'] ?? null) ? $data['number'] : throw new PolicyViolation('no pull request number');
    $pr = new PullRequest($number, 'open', false, null, '', $policy->repository, '', is_string($data['title'] ?? null) ? $data['title'] : '', is_string($data['body'] ?? null) ? $data['body'] : '', $labels);
    $github = Cli::github($policy);
    $messages = $github->pullRequestCommitMessages($number);
    $files = $github->pullRequestFiles($number);
    $classification = (new Classifier())->classify($pr, $messages, $files, count($messages) >= 250 || count($files) >= 3000);

    $message = "Release classification: {$classification->bump->value} ({$classification->reason}).";
    echo $message, "\n";
    Cli::summary(Notes::text($message));
} catch (PolicyViolation|JsonException|RuntimeException $e) {
    fwrite(STDERR, "release classification failed: {$e->getMessage()}\n");
    Cli::summary('Release classification failed: ' . Notes::text($e->getMessage()));
    exit(1);
}
