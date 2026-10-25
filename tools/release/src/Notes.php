<?php

declare(strict_types=1);

namespace ImagePuzzle\Release;

use ImagePuzzle\Release\GitHub\PullRequest;

/**
 * Release notes for one release. Titles, bodies and commit messages come
 * from contributors, so they are escaped as text: HTML is neutralised,
 * mentions do not notify, and nothing is ever executed.
 */
final class Notes
{
    /**
     * @param list<string> $commitMessages
     * @param list<string> $directCommits  commits on the branch without a pull request, included in this release
     */
    public static function build(Policy $policy, ReleaseMetadata $release, PullRequest $pr, array $commitMessages, array $directCommits): string
    {
        $repo = 'https://github.com/' . $policy->repository;
        $short = substr($release->acceptedSha, 0, 12);
        $lines = [
            "Released from #{$pr->number}: " . self::text($pr->title),
            '',
            "- Pull request: {$repo}/pull/{$pr->number}",
            "- Accepted commit: [`{$short}`]({$repo}/commit/{$release->acceptedSha})",
            '- Previous version: ' . ($release->predecessor === null ? 'none' : (string) $release->predecessor),
            "- Bump: {$release->bump->value}: " . self::text($release->reason),
            '',
            '## Changes',
            '',
        ];
        $subjects = [];
        foreach ($commitMessages as $message) {
            $line = strtok($message, "\n");
            $subject = trim($line === false ? '' : $line);
            if ($subject !== '' && !in_array($subject, $subjects, true)) {
                $subjects[] = $subject;
            }
        }
        foreach ($subjects === [] ? [$pr->title] : array_slice($subjects, 0, 50) as $subject) {
            $lines[] = '- ' . self::text($subject);
        }
        if (count($subjects) > 50) {
            $lines[] = '- … and ' . (count($subjects) - 50) . ' more commits';
        }
        if ($directCommits !== []) {
            $lines[] = '';
            $lines[] = 'Also included, pushed to ' . self::text($policy->branch) . ' without a pull request: '
                . implode(', ', array_map(static fn (string $sha): string => '`' . substr($sha, 0, 12) . '`', $directCommits)) . '.';
        }
        if ($release->bump === Bump::Major) {
            $lines[] = '';
            $lines[] = '## Migration';
            $lines[] = '';
            $lines[] = "This release contains breaking changes. See [docs/MIGRATION.md]({$repo}/blob/{$release->version}/docs/MIGRATION.md).";
            $section = self::migrationSection($pr->body);
            if ($section !== '') {
                $lines[] = '';
                foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $section)) as $line) {
                    $lines[] = '> ' . self::text($line);
                }
            }
        }
        $lines[] = '';
        $lines[] = '## Install';
        $lines[] = '';
        $lines[] = '```bash';
        $lines[] = "composer require {$policy->composerPackage}:{$release->version}";
        $lines[] = '```';

        return implode("\n", $lines) . "\n";
    }

    /** Escapes contributor text for a Markdown release body. */
    public static function text(string $text): string
    {
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
        $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');

        return str_replace('@', '&#64;', $text);
    }

    private static function migrationSection(string $body): string
    {
        if (preg_match('/^#{2,3}\s*Migration\s*$\R(.*?)(?=^#{1,3}\s|\z)/ims', $body, $m) !== 1) {
            return '';
        }

        return mb_substr(trim($m[1]), 0, 4000);
    }
}
