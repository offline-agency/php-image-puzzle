<?php

declare(strict_types=1);

namespace ImagePuzzle\Release;

/**
 * The files a read-only build job produced for one accepted commit, as
 * described by its release-manifest.json. The publisher only uploads a set
 * whose commit, version and SHA-256 checksums all match; it never builds.
 */
final readonly class ArtifactSet
{
    public const MANIFEST = 'release-manifest.json';
    public const FORMAT = 'image-puzzle/release-artifacts';

    /**
     * @param list<array{name: string, sha256: string, size: int}> $files
     * @param list<string>                                         $modes
     * @param array<string, string>                                $packages name => version
     */
    private function __construct(
        public string $directory,
        public string $sha,
        public string $version,
        public array $files,
        public array $modes,
        public array $packages,
    ) {
    }

    public static function load(string $directory): self
    {
        $raw = @file_get_contents($directory . '/' . self::MANIFEST);
        if ($raw === false) {
            throw new PolicyViolation('artifacts: ' . self::MANIFEST . ' is missing');
        }
        $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['format'] ?? null) !== self::FORMAT || !is_string($data['sha'] ?? null) || !is_string($data['version'] ?? null)) {
            throw new PolicyViolation('artifacts: unexpected manifest format');
        }
        $files = [];
        foreach (is_array($data['files'] ?? null) ? $data['files'] : [] as $file) {
            if (!is_array($file) || !is_string($file['name'] ?? null) || !is_string($file['sha256'] ?? null) || !is_int($file['size'] ?? null)
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]{0,127}$/', $file['name']) !== 1 || preg_match('/^[0-9a-f]{64}$/', $file['sha256']) !== 1) {
                throw new PolicyViolation('artifacts: invalid file entry');
            }
            $files[] = ['name' => $file['name'], 'sha256' => $file['sha256'], 'size' => $file['size']];
        }
        $modes = array_values(array_filter(is_array($data['modes'] ?? null) ? $data['modes'] : [], static fn (mixed $m): bool => is_string($m) && preg_match('/^[a-z]+$/', $m) === 1));
        $packages = [];
        foreach (is_array($data['packages'] ?? null) ? $data['packages'] : [] as $name => $version) {
            if (is_string($name) && is_string($version)) {
                $packages[$name] = $version;
            }
        }
        if ($files === []) {
            throw new PolicyViolation('artifacts: no files');
        }

        return new self($directory, $data['sha'], $data['version'], $files, $modes, $packages);
    }

    /** Fails unless the set was built from $sha for $version and every file is intact. */
    public function verify(string $sha, string $version): void
    {
        if ($this->sha !== $sha) {
            throw new PolicyViolation("artifacts were built from {$this->sha}, not the accepted commit {$sha}");
        }
        if ($this->version !== $version) {
            throw new PolicyViolation("artifacts were built for {$this->version}, not {$version}");
        }
        $names = array_column($this->files, 'name');
        $missing = array_diff(self::requiredNames($version), $names);
        if ($missing !== []) {
            throw new PolicyViolation('artifacts are incomplete, missing: ' . implode(', ', $missing));
        }
        if (in_array('SHA256SUMS', $names, true)) {
            throw new PolicyViolation('artifacts must not contain SHA256SUMS; the publisher writes it');
        }
        foreach ($this->files as $file) {
            $path = $this->path($file['name']);
            if (!is_file($path)) {
                throw new PolicyViolation("artifact {$file['name']} is missing");
            }
            if (filesize($path) !== $file['size'] || hash_file('sha256', $path) !== $file['sha256']) {
                throw new PolicyViolation("artifact {$file['name']} does not match its checksum");
            }
        }
    }

    /**
     * Every file a release must attach (see ArtifactBuilder).
     *
     * @return list<string>
     */
    public static function requiredNames(string $version): array
    {
        $names = ["image-puzzle-player-{$version}.iife.js", "image-puzzle-player-{$version}.bundle.js"];
        foreach (ArtifactBuilder::WORKSPACES as $workspace) {
            $names[] = "offline-agency-image-puzzle-{$workspace}-{$version}.tgz";
        }
        foreach (ArtifactBuilder::INTEGRATIONS as $integration) {
            $names[] = "image-puzzle-{$integration}-{$version}.zip";
        }

        return $names;
    }

    public function path(string $name): string
    {
        return $this->directory . '/' . $name;
    }

    /** SHA256SUMS in the format of sha256sum. */
    public function checksums(): string
    {
        return implode('', array_map(static fn (array $f): string => "{$f['sha256']}  {$f['name']}\n", $this->files));
    }

    public function notes(): string
    {
        $lines = ['', '## Artifacts and compatibility', '', 'All packages of this repository are released in lockstep with this version:', ''];
        foreach ($this->packages as $name => $version) {
            $lines[] = '- `' . Notes::text($name) . '` ' . Notes::text($version);
        }
        if ($this->modes !== []) {
            $lines[] = '';
            $lines[] = 'Game modes: ' . implode(', ', $this->modes) . '.';
        }
        $lines[] = '';
        $lines[] = 'Attached files (checksums in `SHA256SUMS`): ' . implode(', ', array_map(static fn (array $f): string => '`' . $f['name'] . '`', $this->files)) . '.';
        $lines[] = 'Composer installs the PHP package, including the prebuilt player in `resources/dist`, from the tag. The npm packages and the Laravel integrations are attached here; registry publication is a separate step (see docs/release/RUNBOOK.md).';

        return implode("\n", $lines) . "\n";
    }
}
