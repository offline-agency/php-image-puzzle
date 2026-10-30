<?php

declare(strict_types=1);

use ImagePuzzle\Release\Bump;
use ImagePuzzle\Release\Classifier;
use ImagePuzzle\Release\GitHub\ChangedFile;
use ImagePuzzle\Release\GitHub\PullRequest;
use ImagePuzzle\Release\PolicyViolation;
use ImagePuzzle\Release\ReleaseMetadata;
use ImagePuzzle\Release\Version;

/*
 * Version arithmetic, the pre-1.0 rule and the pre-merge classification of
 * labels and Conventional Commit metadata.
 */

/**
 * @param list<string> $labels
 */
function releasePr(string $title, array $labels = [], string $body = ''): PullRequest
{
    return new PullRequest(7, 'open', false, null, 'master', 'offline-agency/php-image-puzzle', 'offline-agency/php-image-puzzle', $title, $body, $labels);
}

function bumped(string $from, Bump $bump, bool $explicitMajor = false): string
{
    $version = Version::parse($from) ?? throw new RuntimeException("bad version {$from}");

    return (string) $version->bump($bump, $explicitMajor);
}

it('bumps stable versions by SemVer', function (): void {
    expect(bumped('1.0.2', Bump::Patch))->toBe('1.0.3')
        ->and(bumped('1.0.2', Bump::Minor))->toBe('1.1.0')
        ->and(bumped('1.4.2', Bump::Major))->toBe('2.0.0');
});

it('keeps pre-1.0 releases below 1.0 unless release:major is explicit', function (): void {
    expect(bumped('0.0.0', Bump::Patch))->toBe('0.1.0')
        ->and(bumped('0.3.1', Bump::Major))->toBe('0.4.0')
        ->and(bumped('0.3.1', Bump::Minor))->toBe('0.3.2')
        ->and(bumped('0.3.1', Bump::Patch))->toBe('0.3.2')
        ->and(bumped('0.3.1', Bump::Major, true))->toBe('1.0.0');
});

it('parses only unprefixed X.Y.Z tags', function (): void {
    expect(Version::parse('1.0.2'))->not->toBeNull()
        ->and(Version::parse('v1.0.2'))->toBeNull()
        ->and(Version::parse('1.0'))->toBeNull()
        ->and(Version::parse('01.0.0'))->toBeNull()
        ->and(Version::parse('1.0.0-rc.1'))->toBeNull();
});

it('derives the bump from Conventional Commit metadata', function (): void {
    $classifier = new Classifier();

    expect($classifier->classify(releasePr('docs: typo'), [], [])->bump)->toBe(Bump::Patch)
        ->and($classifier->classify(releasePr('fix: x'), ['feat(player): y'], [])->bump)->toBe(Bump::Minor)
        ->and($classifier->classify(releasePr('feat!: drop PHP 8.3'), [], [])->bump)->toBe(Bump::Major)
        ->and($classifier->classify(releasePr('fix: x', [], "text\n\nBREAKING CHANGE: renamed"), [], [])->bump)->toBe(Bump::Major)
        ->and($classifier->classify(releasePr('chore: x', ['release:minor']), [], [])->bump)->toBe(Bump::Minor);
});

it('rejects contradictory, unknown or too low release labels', function (string $title, string $labels, string $message): void {
    expect(fn () => (new Classifier())->classify(releasePr($title, explode(',', $labels)), [], []))
        ->toThrow(PolicyViolation::class, $message);
})->with([
    'two labels' => ['fix: x', 'release:patch,release:minor', 'contradictory'],
    'unknown label' => ['fix: x', 'release:huge', 'unknown release label'],
    'label below a feature' => ['feat: x', 'release:patch', 'contradicts'],
    'label below a breaking change' => ['feat!: x', 'release:minor', 'contradicts'],
]);

it('requires a label for runtime requirement and published schema changes', function (): void {
    $composer = new ChangedFile('composer.json', 'modified', "-        \"php\": \"~8.4.0\",\n+        \"php\": \"~8.5.0\",");
    $schema = new ChangedFile('docs/schemas/v2/manifest.schema.json', 'modified');

    expect(fn () => (new Classifier())->classify(releasePr('chore: bump'), [], [$composer]))->toThrow(PolicyViolation::class, 'need a release label')
        ->and(fn () => (new Classifier())->classify(releasePr('fix: schema'), [], [$schema]))->toThrow(PolicyViolation::class, 'need a release label')
        ->and((new Classifier())->classify(releasePr('fix: schema', ['release:major']), [], [$schema])->explicitMajor)->toBeTrue()
        ->and((new Classifier())->classify(releasePr('feat: new schema'), [], [new ChangedFile('docs/schemas/v3/x.json', 'added')])->bump)->toBe(Bump::Minor);
});

it('round-trips the release metadata stored in the tag', function (): void {
    $metadata = new ReleaseMetadata(12, str_repeat('b', 40), Version::parse('1.1.0') ?? Version::initial(), Version::parse('1.0.2'), Bump::Minor, 'a feature in the pull request title', str_repeat('a', 40));
    $parsed = ReleaseMetadata::parse($metadata->comment());

    expect($parsed)->toEqual($metadata)
        ->and(ReleaseMetadata::parse(str_replace('predecessor: 1.0.2', 'predecessor: junk', $metadata->comment())))->toBeNull()
        ->and(ReleaseMetadata::parse('no marker here'))->toBeNull();
});
