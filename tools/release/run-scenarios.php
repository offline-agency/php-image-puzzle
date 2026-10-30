<?php

declare(strict_types=1);

/*
 * Runs every mandatory release scenario against the fake GitHub API and a
 * temporary Git repository, and prints one line of evidence per scenario.
 * Nothing is sent to GitHub or Packagist.
 *   php tools/release/run-scenarios.php [--json]
 */

use ImagePuzzle\Release\Testing\ReleaseWorld;
use ImagePuzzle\Release\Testing\Scenarios;

require __DIR__ . '/autoload.php';

$json = in_array('--json', array_slice(\ImagePuzzle\Release\Cli::argv(), 1), true);
$results = [];
$failures = 0;
foreach (Scenarios::all() as $name => $scenario) {
    $world = new ReleaseWorld();
    try {
        $results[] = ['scenario' => $name, 'passed' => true, 'evidence' => $scenario($world)];
    } catch (Throwable $e) {
        $failures++;
        $results[] = ['scenario' => $name, 'passed' => false, 'evidence' => $e::class . ': ' . $e->getMessage()];
    } finally {
        $world->cleanUp();
    }
}

if ($json) {
    echo json_encode(['passed' => count($results) - $failures, 'failed' => $failures, 'scenarios' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
} else {
    foreach ($results as $result) {
        echo ($result['passed'] ? 'ok    ' : 'FAIL  ') . $result['scenario'] . "\n      " . $result['evidence'] . "\n";
    }
    echo count($results) - $failures . ' of ' . count($results) . " release scenarios passed\n";
}
exit($failures === 0 ? 0 : 1);
