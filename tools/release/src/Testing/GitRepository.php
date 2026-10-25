<?php

declare(strict_types=1);

namespace ImagePuzzle\Release\Testing;

/**
 * A throwaway Git repository for the release scenarios, so merges, squashes,
 * rebases and ancestry are real Git behaviour rather than assumptions.
 */
final class GitRepository
{
    private int $counter = 0;

    private function __construct(public readonly string $path)
    {
    }

    public static function create(): self
    {
        $path = sys_get_temp_dir() . '/image-puzzle-release-' . bin2hex(random_bytes(6));
        mkdir($path, 0o700);
        $repo = new self($path);
        $repo->git('init', '--quiet', '--initial-branch=master');
        $repo->git('config', 'user.name', 'Release Scenario');
        $repo->git('config', 'user.email', 'scenario@example.test');
        $repo->git('config', 'commit.gpgsign', 'false');

        return $repo;
    }

    public function commit(string $message): string
    {
        $this->counter++;
        file_put_contents("{$this->path}/file-{$this->counter}.txt", "{$this->counter}\n");
        $this->git('add', '--all');
        $this->git('commit', '--quiet', '-m', $message);

        return $this->head();
    }

    public function checkout(string $branch, bool $create = false, ?string $from = null): void
    {
        $create ? $this->git('checkout', '--quiet', '-b', $branch, ...($from === null ? [] : [$from])) : $this->git('checkout', '--quiet', $branch);
    }

    public function head(): string
    {
        return $this->git('rev-parse', 'HEAD');
    }

    public function revParse(string $ref): string
    {
        return $this->git('rev-parse', $ref);
    }

    public function isAncestor(string $ancestor, string $descendant): bool
    {
        return $this->run(['merge-base', '--is-ancestor', $ancestor, $descendant])[0] === 0;
    }

    /**
     * @return list<string>
     */
    public function revList(string $base, string $head): array
    {
        $out = $this->git('rev-list', '--reverse', '--topo-order', "{$base}..{$head}");

        return $out === '' ? [] : explode("\n", $out);
    }

    public function git(string ...$args): string
    {
        [$code, $out, $err] = $this->run(array_values($args));
        if ($code !== 0) {
            throw new \RuntimeException('git ' . implode(' ', $args) . " failed: {$err}");
        }

        return trim($out);
    }

    public function remove(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $entry) {
            if ($entry instanceof \SplFileInfo) {
                $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
        }
        rmdir($this->path);
    }

    /**
     * @param list<string> $args
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private function run(array $args): array
    {
        $process = proc_open(['git', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->path, ['GIT_CONFIG_NOSYSTEM' => '1', 'HOME' => $this->path, 'PATH' => (string) getenv('PATH')]);
        if (!is_resource($process)) {
            throw new \RuntimeException('cannot run git');
        }
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);

        return [proc_close($process), $out, $err];
    }
}
