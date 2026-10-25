<?php

declare(strict_types=1);

namespace ImagePuzzle\Release;

use ImagePuzzle\Release\Http\Http;

/**
 * Waits until a Composer repository (Packagist by default) serves a version
 * with the expected source reference. Indexing is a separate outcome from
 * the GitHub Release: a timeout here leaves the release in place and can be
 * retried with the same command, it never causes a new version.
 */
final class PackagistVerifier
{
    /** @var \Closure(int): void */
    private \Closure $sleep;

    /**
     * @param \Closure(int): void|null $sleep receives seconds to wait
     */
    public function __construct(
        private readonly Http $http,
        private readonly string $repositoryUrl = 'https://repo.packagist.org',
        private readonly int $attempts = 10,
        private readonly int $firstDelay = 15,
        private readonly int $maxDelay = 300,
        ?\Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    /**
     * @return array{found: bool, attempts: int, detail: string}
     */
    public function waitFor(string $package, string $version, string $sha): array
    {
        $url = rtrim($this->repositoryUrl, '/') . "/p2/{$package}.json";
        $delay = $this->firstDelay;
        $detail = 'not checked';
        for ($attempt = 1; $attempt <= $this->attempts; $attempt++) {
            try {
                $response = $this->http->request('GET', $url, ['Accept: application/json']);
                if ($response->status === 200) {
                    $entry = self::find($response->json(), $package, $version);
                    if ($entry === null) {
                        $detail = "{$version} is not listed yet";
                    } elseif (($entry['source'] ?? null) !== $sha && ($entry['dist'] ?? null) !== $sha) {
                        return ['found' => false, 'attempts' => $attempt, 'detail' => "{$version} is listed with reference " . ($entry['source'] ?? 'none') . ", not {$sha}: check the tag and the Packagist package"];
                    } else {
                        return ['found' => true, 'attempts' => $attempt, 'detail' => "{$package} {$version} is available at {$sha}"];
                    }
                } else {
                    $detail = "HTTP {$response->status} from {$url}";
                }
            } catch (\RuntimeException|\JsonException $e) {
                $detail = $e->getMessage();
            }
            if ($attempt < $this->attempts) {
                ($this->sleep)($delay);
                $delay = min($this->maxDelay, $delay * 2);
            }
        }

        return ['found' => false, 'attempts' => $this->attempts, 'detail' => $detail];
    }

    /**
     * Finds one version in Composer 2 metadata, expanding the minified
     * format (each entry repeats only the keys that changed).
     *
     * @param array<mixed> $metadata
     *
     * @return array{source: ?string, dist: ?string}|null
     */
    public static function find(array $metadata, string $package, string $version): ?array
    {
        $packages = is_array($metadata['packages'] ?? null) ? $metadata['packages'] : [];
        $versions = is_array($packages[$package] ?? null) ? $packages[$package] : [];
        $minified = ($metadata['minified'] ?? null) === 'composer/2.0';
        $current = [];
        foreach ($versions as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if ($minified) {
                foreach ($entry as $key => $value) {
                    if ($value === '__unset') {
                        unset($current[$key]);
                    } else {
                        $current[$key] = $value;
                    }
                }
            } else {
                $current = $entry;
            }
            $name = $current['version'] ?? null;
            if ($name === $version || $name === "v{$version}") {
                $source = is_array($current['source'] ?? null) && is_string($current['source']['reference'] ?? null) ? $current['source']['reference'] : null;
                $dist = is_array($current['dist'] ?? null) && is_string($current['dist']['reference'] ?? null) ? $current['dist']['reference'] : null;

                return ['source' => $source, 'dist' => $dist];
            }
        }

        return null;
    }
}
