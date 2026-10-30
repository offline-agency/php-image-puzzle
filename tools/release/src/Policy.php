<?php

declare(strict_types=1);

namespace ImagePuzzle\Release;

/**
 * .github/release-policy.json: which repository and branch publish, the
 * activation anchor (an existing release tag and the commit it must point
 * at), the exact validation that must pass for a merged commit, and the
 * reviewed exclusions: merged pull requests whose accepted commit failed
 * validation for good and whose changes ship with the next release instead.
 */
final readonly class Policy
{
    /**
     * @param array<int, array{sha: string, reason: string}> $excluded by pull request number
     */
    public function __construct(
        public string $repository,
        public string $branch,
        public string $anchorTag,
        public string $anchorCommit,
        public string $validationWorkflow,
        public string $validationEvent,
        public string $validationJob,
        public string $composerPackage,
        public array $excluded = [],
    ) {
        if (preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~', $repository) !== 1) {
            throw new PolicyViolation('policy: repository must be owner/name');
        }
        if (preg_match('/^[0-9a-f]{40}$/', $anchorCommit) !== 1 || Version::parse($anchorTag) === null) {
            throw new PolicyViolation('policy: the anchor needs a release tag and a full commit SHA');
        }
    }

    public static function fromFile(string $path): self
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new PolicyViolation("policy: cannot read {$path}");
        }
        $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new PolicyViolation('policy: expected an object');
        }

        return new self(
            self::text($data, 'repository'),
            self::text($data, 'branch'),
            self::text(self::section($data, 'anchor'), 'tag'),
            self::text(self::section($data, 'anchor'), 'commit'),
            self::text(self::section($data, 'validation'), 'workflow'),
            self::text(self::section($data, 'validation'), 'event'),
            self::text(self::section($data, 'validation'), 'job'),
            self::text($data, 'composerPackage'),
            self::exclusions($data['excluded'] ?? []),
        );
    }

    /**
     * @return array<int, array{sha: string, reason: string}>
     */
    private static function exclusions(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new PolicyViolation('policy: excluded must be a list');
        }
        $found = [];
        foreach ($value as $entry) {
            if (!is_array($entry) || !is_int($entry['pullRequest'] ?? null) || !is_string($entry['sha'] ?? null) || preg_match('/^[0-9a-f]{40}$/', $entry['sha']) !== 1) {
                throw new PolicyViolation('policy: each exclusion needs pullRequest, a full sha and a reason');
            }
            $found[$entry['pullRequest']] = ['sha' => $entry['sha'], 'reason' => self::text($entry, 'reason')];
        }

        return $found;
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    private static function section(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value)) {
            throw new PolicyViolation("policy: {$key} must be an object");
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     */
    private static function text(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new PolicyViolation("policy: {$key} must be a non-empty string");
        }

        return $value;
    }
}
