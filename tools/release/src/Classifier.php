<?php

declare(strict_types=1);

namespace ImagePuzzle\Release;

use ImagePuzzle\Release\GitHub\ChangedFile;
use ImagePuzzle\Release\GitHub\PullRequest;

/**
 * Decides major, minor or patch for one merged pull request, the same way
 * before the merge (check-pr.php) and when publishing:
 *
 * - Conventional Commit metadata in the title, the body and every commit
 *   of the pull request: `type!:` or a `BREAKING CHANGE:` footer means
 *   major, `feat:` minor, anything else patch. Every pull request is at
 *   least a patch, including documentation, CI and dependency changes.
 * - At most one of the labels release:major, release:minor, release:patch.
 *   A label may raise the detected level but never lower it: a breaking
 *   change labelled release:patch is refused.
 * - Changes to the promised runtime support (the php or ext-* requirements
 *   in composer.json) or to a published schema (docs/schemas, existing files)
 *   need a release label, so a maintainer decides whether they break users.
 *
 * Inputs are contributor-written text; they are only matched with regular
 * expressions.
 */
final class Classifier
{
    private const LABELS = ['release:patch' => Bump::Patch, 'release:minor' => Bump::Minor, 'release:major' => Bump::Major];

    /**
     * @param list<string>      $commitMessages
     * @param list<ChangedFile> $files
     */
    public function classify(PullRequest $pr, array $commitMessages, array $files, bool $truncated = false): Classification
    {
        $labels = array_values(array_filter($pr->labels, static fn (string $label): bool => str_starts_with(strtolower($label), 'release:')));
        $known = array_values(array_intersect(array_map('strtolower', $labels), array_keys(self::LABELS)));
        if (count($labels) !== count($known)) {
            throw new PolicyViolation('unknown release label: ' . implode(', ', array_diff($labels, $known)) . ' (use release:major, release:minor or release:patch)');
        }
        if (count($known) > 1) {
            throw new PolicyViolation('contradictory release labels: ' . implode(', ', $known) . ' (keep one)');
        }
        $label = $known === [] ? null : self::LABELS[$known[0]];

        [$detected, $why] = $this->detect($pr, $commitMessages);
        $sensitive = $this->sensitiveChanges($files);

        if ($label === null) {
            if ($truncated) {
                throw new PolicyViolation('the pull request has more commits or files than the API lists (250 commits, 3000 files); add a release label');
            }
            if ($sensitive !== []) {
                throw new PolicyViolation('these changes need a release label (release:major if they break users): ' . implode('; ', $sensitive));
            }

            return new Classification($detected, false, $why);
        }
        if ($label->rank() < $detected->rank()) {
            throw new PolicyViolation("label release:{$label->value} contradicts {$why}; use release:{$detected->value} or change the commits");
        }
        $reason = "label release:{$label->value}" . ($label === $detected ? " ({$why})" : " raises {$why}");
        if ($sensitive !== []) {
            $reason .= '; maintainer-classified changes: ' . implode('; ', $sensitive);
        }

        return new Classification($label, $label === Bump::Major, $reason);
    }

    /**
     * @param list<string> $commitMessages
     *
     * @return array{0: Bump, 1: string}
     */
    private function detect(PullRequest $pr, array $commitMessages): array
    {
        $sources = ['the pull request title' => $pr->title, 'the pull request body' => $pr->body];
        foreach ($commitMessages as $index => $message) {
            $sources['commit ' . ($index + 1) . ' "' . self::subject($message) . '"'] = $message;
        }
        $bump = Bump::Patch;
        $why = 'no feature or breaking change (patch by default)';
        foreach ($sources as $where => $text) {
            $found = self::level($text, $where === 'the pull request body');
            if ($found !== null && $found->rank() > $bump->rank()) {
                $bump = $found;
                $why = ($found === Bump::Major ? 'a breaking change in ' : 'a feature in ') . $where;
            }
        }

        return [$bump, $why];
    }

    private static function level(string $text, bool $bodyOnly): ?Bump
    {
        if (preg_match('/^BREAKING[ -]CHANGE:/m', $text) === 1) {
            return Bump::Major;
        }
        if ($bodyOnly) {
            return null;
        }
        if (preg_match('/^(\w+)(?:\([^)\r\n]*\))?(!)?:\s/', $text, $m) !== 1) {
            return null;
        }
        if (($m[2] ?? '') === '!') {
            return Bump::Major;
        }

        return strtolower($m[1]) === 'feat' ? Bump::Minor : Bump::Patch;
    }

    /**
     * @param list<ChangedFile> $files
     *
     * @return list<string>
     */
    private function sensitiveChanges(array $files): array
    {
        $found = [];
        foreach ($files as $file) {
            // The API omits the patch of large diffs: then assume the worst.
            if ($file->path === 'composer.json' && ($file->patch === '' || preg_match('/^[-+]\s*"(php|ext-[a-z0-9_]+)"\s*:/m', $file->patch) === 1)) {
                $found[] = 'composer.json may change the php or extension requirements';
            }
            if (str_starts_with($file->path, 'docs/schemas/') && $file->status !== 'added') {
                $found[] = "{$file->path} ({$file->status}) is a published schema";
            }
        }

        return array_values(array_unique($found));
    }

    private static function subject(string $message): string
    {
        $line = strtok($message, "\n");

        return mb_substr($line === false ? '' : trim($line), 0, 72);
    }
}
