<?php

declare(strict_types=1);

use ImagePuzzle\Release\Testing\ReleaseWorld;
use ImagePuzzle\Release\Testing\Scenarios;

/*
 * The mandatory release scenarios (docs/release/RUNBOOK.md) run against the
 * fake GitHub API and a temporary Git repository: no request leaves the
 * machine. Each scenario returns its evidence or throws.
 */

dataset('release scenarios', array_keys(Scenarios::all()));

it('passes the release scenario', function (string $name): void {
    $world = new ReleaseWorld();
    try {
        $evidence = Scenarios::all()[$name]($world);
    } finally {
        $world->cleanUp();
    }

    expect($evidence)->not->toBe('');
})->with('release scenarios');

it('covers every mandatory scenario', function (): void {
    expect(count(Scenarios::all()))->toBeGreaterThanOrEqual(23);
});
