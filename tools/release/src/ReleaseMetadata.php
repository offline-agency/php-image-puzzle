<?php

declare(strict_types=1);

namespace ImagePuzzle\Release;

/**
 * The durable record of one release, stored in the annotated tag message
 * and repeated in the GitHub Release body:
 *
 *     Release 1.0.3
 *
 *     image-puzzle-release: 1
 *     pull-request: 42
 *     accepted-sha: <40 hex>
 *     version: 1.0.3
 *     predecessor: 1.0.2
 *     bump: patch
 *     reason: no feature or breaking change (patch by default)
 *     tooling-sha: <40 hex>
 *
 * The planner recognises its own tags by this record, which is what makes
 * reruns no-ops and lets a release interrupted after the tag be completed
 * with the same version.
 */
final readonly class ReleaseMetadata
{
    public const MARKER = 'image-puzzle-release: 1';

    public function __construct(
        public int $pullRequest,
        public string $acceptedSha,
        public Version $version,
        public ?Version $predecessor,
        public Bump $bump,
        public string $reason,
        public string $toolingSha,
    ) {
        if (preg_match('/^[0-9a-f]{40}$/', $acceptedSha) !== 1 || preg_match('/^[0-9a-f]{40}$/', $toolingSha) !== 1) {
            throw new PolicyViolation('release metadata needs full commit SHAs');
        }
    }

    /**
     * The release section of a plan written by plan.php.
     *
     * @param array<mixed> $release
     */
    public static function fromPlan(array $release): self
    {
        $version = Version::parse(is_string($release['version'] ?? null) ? $release['version'] : '');
        $bump = Bump::tryFrom(is_string($release['bump'] ?? null) ? $release['bump'] : '');
        if ($version === null || $bump === null || !is_int($release['pullRequest'] ?? null)) {
            throw new PolicyViolation('the plan has no valid release');
        }
        $previous = is_string($release['previousVersion'] ?? null) ? Version::parse($release['previousVersion']) : null;

        return new self(
            $release['pullRequest'],
            is_string($release['acceptedSha'] ?? null) ? $release['acceptedSha'] : '',
            $version,
            $previous,
            $bump,
            is_string($release['bumpReason'] ?? null) ? $release['bumpReason'] : '',
            is_string($release['toolingSha'] ?? null) ? $release['toolingSha'] : '',
        );
    }

    public function tagMessage(): string
    {
        return "Release {$this->version}\n\n" . $this->record() . "\n";
    }

    /** The record as an HTML comment, for the release body. */
    public function comment(): string
    {
        return "<!--\n" . $this->record() . "\n-->";
    }

    public static function parse(?string $text): ?self
    {
        if ($text === null || !str_contains($text, self::MARKER)) {
            return null;
        }
        // Only the last record counts: it is written after the notes, so text
        // from contributors in front of it can never supply the fields.
        $text = substr($text, (int) strrpos($text, self::MARKER));
        $fields = [];
        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $text)) as $line) {
            if (preg_match('/^([a-z-]+): (.*)$/', $line, $m) === 1 && !isset($fields[$m[1]])) {
                $fields[$m[1]] = $m[2];
            }
        }
        $version = Version::parse($fields['version'] ?? '');
        $bump = Bump::tryFrom($fields['bump'] ?? '');
        $number = $fields['pull-request'] ?? '';
        if ($version === null || $bump === null || preg_match('/^[1-9]\d{0,9}$/', $number) !== 1) {
            return null;
        }
        $previous = $fields['predecessor'] ?? 'none';
        $predecessor = $previous === 'none' ? null : Version::parse($previous);
        if ($previous !== 'none' && $predecessor === null) {
            return null;
        }
        try {
            return new self((int) $number, $fields['accepted-sha'] ?? '', $version, $predecessor, $bump, $fields['reason'] ?? '', $fields['tooling-sha'] ?? '');
        } catch (PolicyViolation) {
            return null;
        }
    }

    private function record(): string
    {
        $reason = str_replace(self::MARKER, 'image-puzzle-release 1', (string) preg_replace('/[\r\n\x{2028}\x{2029}]+/u', ' ', $this->reason));

        return implode("\n", [
            self::MARKER,
            "pull-request: {$this->pullRequest}",
            "accepted-sha: {$this->acceptedSha}",
            "version: {$this->version}",
            'predecessor: ' . ($this->predecessor === null ? 'none' : (string) $this->predecessor),
            "bump: {$this->bump->value}",
            'reason: ' . str_replace('-->', '- ->', $reason),
            "tooling-sha: {$this->toolingSha}",
        ]);
    }
}
