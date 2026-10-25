<?php

declare(strict_types=1);

namespace ImagePuzzle\Release;

/**
 * Builds the release artifacts of one commit in an unprivileged job (it may
 * run npm; it never holds a write token):
 *
 * - checks that the prebuilt players committed for Composer consumers
 *   (resources/dist, integrations/<name>/resources/dist) equal a fresh
 *   build and are part of the Composer archive of that commit;
 * - copies the browser bundles, packs the npm workspaces with every
 *   internal version set to the release version (lockstep), and archives
 *   each Laravel integration from the commit;
 * - writes release-manifest.json with the commit, version, modes, package
 *   versions and SHA-256 checksums that the publisher verifies.
 */
final class ArtifactBuilder
{
    public const WORKSPACES = ['engine', 'player', 'react', 'vue'];
    public const INTEGRATIONS = ['livewire', 'filament'];

    public function __construct(private readonly string $root)
    {
    }

    /**
     * @return list<string> problems with the committed prebuilt players
     */
    public function prebuiltProblems(): array
    {
        $dist = "{$this->root}/packages/player/dist";
        $copies = ['resources/dist/image-puzzle-player.iife.js' => "{$dist}/image-puzzle-player.iife.js"];
        foreach (self::INTEGRATIONS as $name) {
            if (is_dir("{$this->root}/integrations/{$name}/resources/dist")) {
                $copies["integrations/{$name}/resources/dist/image-puzzle-player.bundle.js"] = "{$dist}/image-puzzle-player.bundle.js";
            }
        }
        $problems = [];
        foreach ($copies as $copy => $built) {
            if (!is_file($built)) {
                $problems[] = "{$built} is missing: build the player first";
            } elseif (!is_file("{$this->root}/{$copy}")) {
                $problems[] = "{$copy} is missing";
            } elseif (hash_file('sha256', "{$this->root}/{$copy}") !== hash_file('sha256', $built)) {
                $problems[] = "{$copy} differs from a fresh build: run npm run build && npm run sync:bundles and commit";
            }
        }

        return $problems;
    }

    /**
     * Composer takes a package version from the tag. A "version" field that
     * disagrees makes Packagist skip the tag, which is why 1.0.1 and 1.0.2
     * (both declaring 1.0.0) were never indexed.
     *
     * @return list<string> composer.json files that would hide the release
     */
    public function composerProblems(): array
    {
        $problems = [];
        foreach (['composer.json', ...array_map(static fn (string $name): string => "integrations/{$name}/composer.json", self::INTEGRATIONS)] as $file) {
            $raw = is_file("{$this->root}/{$file}") ? file_get_contents("{$this->root}/{$file}") : false;
            $data = $raw === false ? null : json_decode($raw, true);
            if (!is_array($data)) {
                $problems[] = "{$file} is missing or not valid JSON";
            } elseif (array_key_exists('version', $data)) {
                $problems[] = "{$file} declares a version: remove it, Packagist ignores tags that disagree with it";
            }
        }

        return $problems;
    }

    /**
     * @param list<string> $modes
     */
    public function build(string $sha, Version $version, string $out, array $modes, bool $packNpm = true): ArtifactSet
    {
        $problems = $this->prebuiltProblems();
        if ($this->git('rev-parse', 'HEAD') !== $sha) {
            $problems[] = "the checkout is not {$sha}";
        }
        try {
            $archived = $this->git('archive', '--format=tar', $sha, 'resources/dist/image-puzzle-player.iife.js');
        } catch (\RuntimeException) {
            $archived = '';
        }
        if ($archived === '') {
            $problems[] = 'resources/dist/image-puzzle-player.iife.js is not in the Composer archive of the commit';
        }
        if ($problems !== []) {
            throw new PolicyViolation("incomplete browser asset distribution:\n- " . implode("\n- ", $problems));
        }
        $composer = $this->composerProblems();
        if ($composer !== []) {
            throw new PolicyViolation("the Composer packages would not be indexed:\n- " . implode("\n- ", $composer));
        }
        if (!is_dir($out) && !mkdir($out, 0o755, true) && !is_dir($out)) {
            throw new \RuntimeException("cannot create {$out}");
        }
        $v = (string) $version;
        $packages = ['offline-agency/image-puzzle' => $v];
        copy("{$this->root}/resources/dist/image-puzzle-player.iife.js", "{$out}/image-puzzle-player-{$v}.iife.js");
        copy("{$this->root}/packages/player/dist/image-puzzle-player.bundle.js", "{$out}/image-puzzle-player-{$v}.bundle.js");

        if ($packNpm) {
            foreach (self::WORKSPACES as $workspace) {
                $packages['@offline-agency/image-puzzle-' . $workspace] = $v;
                $this->pack($workspace, $v, $out);
            }
        }
        foreach (self::INTEGRATIONS as $name) {
            $file = "{$out}/image-puzzle-{$name}-{$v}.zip";
            $this->git('archive', '--format=zip', "--prefix=image-puzzle-{$name}-{$v}/", "--output={$file}", "{$sha}:integrations/{$name}");
            $packages["offline-agency/image-puzzle-{$name}"] = $v;
        }

        $files = [];
        $names = scandir($out);
        foreach ($names === false ? [] : $names as $name) {
            if ($name !== '.' && $name !== '..' && $name !== ArtifactSet::MANIFEST && is_file("{$out}/{$name}")) {
                $files[] = ['name' => $name, 'sha256' => (string) hash_file('sha256', "{$out}/{$name}"), 'size' => (int) filesize("{$out}/{$name}")];
            }
        }
        file_put_contents("{$out}/" . ArtifactSet::MANIFEST, json_encode([
            'format' => ArtifactSet::FORMAT,
            'sha' => $sha,
            'version' => $v,
            'files' => $files,
            'modes' => $modes,
            'packages' => $packages,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

        return ArtifactSet::load($out);
    }

    /** npm pack of one workspace with its version and internal dependencies set to the release version. */
    private function pack(string $workspace, string $version, string $out): void
    {
        $source = "{$this->root}/packages/{$workspace}";
        $copy = sys_get_temp_dir() . "/image-puzzle-pack-{$workspace}-" . bin2hex(random_bytes(4));
        $this->run(['cp', '-R', $source, $copy], $this->root);
        try {
            $package = json_decode((string) file_get_contents("{$copy}/package.json"), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($package)) {
                throw new \RuntimeException("{$workspace}/package.json is not an object");
            }
            $package['version'] = $version;
            foreach (['dependencies', 'peerDependencies', 'optionalDependencies'] as $section) {
                $dependencies = $package[$section] ?? null;
                if (!is_array($dependencies)) {
                    continue;
                }
                foreach (array_keys($dependencies) as $name) {
                    if (is_string($name) && str_starts_with($name, '@offline-agency/image-puzzle-')) {
                        $dependencies[$name] = $version;
                    }
                }
                $package[$section] = $dependencies;
            }
            file_put_contents("{$copy}/package.json", json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
            $this->run(['rm', '-rf', "{$copy}/node_modules"], $this->root);
            $this->run(['npm', 'pack', '--ignore-scripts', '--silent', "--pack-destination={$out}"], $copy);
        } finally {
            $this->run(['rm', '-rf', $copy], $this->root);
        }
    }

    private function git(string ...$args): string
    {
        return $this->run(['git', ...array_values($args)], $this->root);
    }

    /**
     * @param list<string> $command
     */
    private function run(array $command, string $cwd): string
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
        if (!is_resource($process)) {
            throw new \RuntimeException("cannot start {$command[0]}");
        }
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new \RuntimeException(implode(' ', $command) . " failed: {$err}");
        }

        return trim($out);
    }
}
