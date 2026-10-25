<?php

declare(strict_types=1);

namespace ImagePuzzle\Release;

use ImagePuzzle\Release\GitHub\HttpGitHub;
use ImagePuzzle\Release\Http\CurlHttp;

/** Shared helpers of the release scripts: options, the API client and GitHub Actions outputs. */
final class Cli
{
    /**
     * --name=value options; bare --flag gives "1".
     *
     * @param list<string> $argv
     *
     * @return array<string, string>
     */
    public static function options(array $argv): array
    {
        $options = [];
        foreach (array_slice($argv, 1) as $argument) {
            if (preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $argument, $m) !== 1) {
                throw new PolicyViolation("unexpected argument {$argument}");
            }
            $options[$m[1]] = $m[2] ?? '1';
        }

        return $options;
    }

    /**
     * @return list<string>
     */
    public static function argv(): array
    {
        $args = [];
        foreach (is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [] as $arg) {
            if (is_string($arg)) {
                $args[] = $arg;
            }
        }

        return $args;
    }

    public static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    public static function policy(?string $path = null): Policy
    {
        return Policy::fromFile($path ?? self::root() . '/.github/release-policy.json');
    }

    /** The REST client for the policy's repository, refusing to run for another repository. */
    public static function github(Policy $policy): HttpGitHub
    {
        $repository = getenv('GITHUB_REPOSITORY');
        if (is_string($repository) && $repository !== '' && $repository !== $policy->repository) {
            throw new PolicyViolation("this workflow runs in {$repository}, the release policy is for {$policy->repository}");
        }
        $token = getenv('GITHUB_TOKEN');
        if (!is_string($token) || $token === '') {
            throw new PolicyViolation('GITHUB_TOKEN is not set');
        }

        return new HttpGitHub(new CurlHttp(), $policy->repository, $token);
    }

    public static function sha(string $value, string $what): string
    {
        if (preg_match('/^[0-9a-f]{40}$/', $value) !== 1) {
            throw new PolicyViolation("{$what} must be a full commit SHA");
        }

        return $value;
    }

    /**
     * Writes step outputs. Values are restricted to simple tokens, so data
     * from contributors can never inject further outputs.
     *
     * @param array<string, string|int|bool|null> $values
     */
    public static function outputs(array $values): void
    {
        $file = getenv('GITHUB_OUTPUT');
        $lines = '';
        foreach ($values as $name => $value) {
            $text = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            if (preg_match('/^[A-Za-z0-9._:-]*$/', $text) !== 1) {
                throw new PolicyViolation("refusing to write output {$name}");
            }
            $lines .= "{$name}={$text}\n";
        }
        if (is_string($file) && $file !== '') {
            file_put_contents($file, $lines, FILE_APPEND);
        }
    }

    public static function summary(string $markdown): void
    {
        $file = getenv('GITHUB_STEP_SUMMARY');
        if (is_string($file) && $file !== '') {
            file_put_contents($file, $markdown . "\n", FILE_APPEND);
        }
    }

    /** A readable summary of a plan; contributor text is escaped. */
    public static function describe(Plan $plan): string
    {
        $data = $plan->toArray();
        $release = is_array($data['release'] ?? null) ? $data['release'] : [];
        $validation = is_array($data['validation'] ?? null) ? $data['validation'] : [];
        $rows = [
            'Decision' => $plan->decision . ($plan->resume ? ' (resume)' : ''),
            'Reason' => Notes::text($plan->reason),
            'Pull request' => isset($data['pullRequest']) && is_int($data['pullRequest']) ? '#' . $data['pullRequest'] : '-',
            'Branch' => is_string($data['branch'] ?? null) ? $data['branch'] : '-',
            'Accepted SHA' => is_string($data['acceptedSha'] ?? null) ? $data['acceptedSha'] : '-',
            'Validation' => is_string($validation['status'] ?? null) ? $validation['status'] . ' (' . Notes::text(is_string($validation['detail'] ?? null) ? $validation['detail'] : '') . ')' : '-',
            'Previous version' => is_string($release['previousVersion'] ?? null) ? $release['previousVersion'] : '-',
            'Bump' => is_string($release['bump'] ?? null) ? $release['bump'] . ': ' . Notes::text(is_string($release['bumpReason'] ?? null) ? $release['bumpReason'] : '') : '-',
            'Next tag' => is_string($release['tag'] ?? null) ? $release['tag'] : '-',
            'Target SHA' => is_string($release['targetSha'] ?? null) ? $release['targetSha'] : '-',
        ];
        $out = "### Release plan\n\n| | |\n| --- | --- |\n";
        foreach ($rows as $label => $value) {
            $out .= "| {$label} | " . str_replace('|', '\\|', $value) . " |\n";
        }
        $packagist = is_array($data['packagist'] ?? null) ? $data['packagist'] : [];
        if (is_string($packagist['command'] ?? null)) {
            $out .= "\nPackagist verification after publication: `{$packagist['command']}`\n";
        }
        if ($plan->notes !== '') {
            $out .= "\n<details><summary>Release notes</summary>\n\n{$plan->notes}\n</details>\n";
        }

        return $out;
    }
}
