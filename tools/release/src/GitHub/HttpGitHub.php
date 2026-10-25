<?php

declare(strict_types=1);

namespace ImagePuzzle\Release\GitHub;

use ImagePuzzle\Release\Http\Http;
use ImagePuzzle\Release\Http\Response;

/**
 * GitHub REST API client for one repository, authenticated with the
 * workflow's GITHUB_TOKEN. The token only travels in the Authorization
 * header and never appears in errors or output.
 */
final class HttpGitHub implements GitHub
{
    private const API = 'https://api.github.com';
    private const UPLOADS = 'https://uploads.github.com';

    public function __construct(
        private readonly Http $http,
        private readonly string $repository,
        #[\SensitiveParameter]
        private readonly string $token,
    ) {
        if (preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~', $repository) !== 1) {
            throw new \InvalidArgumentException('repository must be owner/name');
        }
    }

    public function pullRequest(int $number): ?PullRequest
    {
        $response = $this->call('GET', "/repos/{$this->repository}/pulls/{$number}", allowMissing: true);
        if ($response === null) {
            return null;
        }
        $pr = $response->json();
        $base = self::map($pr, 'base');
        $head = self::map($pr, 'head');
        $labels = [];
        foreach (is_array($pr['labels'] ?? null) ? $pr['labels'] : [] as $label) {
            if (is_array($label) && is_string($label['name'] ?? null)) {
                $labels[] = $label['name'];
            }
        }

        return new PullRequest(
            self::int($pr, 'number'),
            self::string($pr, 'state'),
            ($pr['merged'] ?? false) === true,
            is_string($pr['merge_commit_sha'] ?? null) ? $pr['merge_commit_sha'] : null,
            self::string($base, 'ref'),
            self::string(self::map($base, 'repo'), 'full_name'),
            self::string(self::map($head, 'repo'), 'full_name'),
            self::string($pr, 'title'),
            self::string($pr, 'body'),
            $labels,
        );
    }

    public function pullRequestCommitMessages(int $number): array
    {
        $messages = [];
        foreach ($this->all("/repos/{$this->repository}/pulls/{$number}/commits?per_page=100") as $commit) {
            $messages[] = self::string(self::map($commit, 'commit'), 'message');
        }

        return $messages;
    }

    public function pullRequestFiles(int $number): array
    {
        $files = [];
        foreach ($this->all("/repos/{$this->repository}/pulls/{$number}/files?per_page=100") as $file) {
            $files[] = new ChangedFile(self::string($file, 'filename'), self::string($file, 'status'), self::string($file, 'patch'));
        }

        return $files;
    }

    public function pullRequestsForCommit(string $sha): array
    {
        $numbers = [];
        foreach ($this->all("/repos/{$this->repository}/commits/" . self::sha($sha) . '/pulls?per_page=100') as $pr) {
            $numbers[] = self::int($pr, 'number');
        }

        return $numbers;
    }

    public function branchHead(string $branch): string
    {
        $data = $this->call('GET', "/repos/{$this->repository}/branches/" . rawurlencode($branch))?->json() ?? [];

        return self::sha(self::string(self::map($data, 'commit'), 'sha'));
    }

    public function isAncestor(string $ancestor, string $descendant): bool
    {
        if ($ancestor === $descendant) {
            return true;
        }
        $data = $this->call('GET', "/repos/{$this->repository}/compare/" . self::sha($ancestor) . '...' . self::sha($descendant) . '?per_page=1')?->json() ?? [];

        return in_array($data['status'] ?? null, ['ahead', 'identical'], true);
    }

    public function commitsBetween(string $base, string $head): array
    {
        $commits = [];
        for ($page = 1; $page <= 100; $page++) {
            $data = $this->call('GET', "/repos/{$this->repository}/compare/" . self::sha($base) . '...' . self::sha($head) . "?per_page=100&page={$page}")?->json() ?? [];
            if (!in_array($data['status'] ?? null, ['ahead', 'identical'], true)) {
                throw new \RuntimeException("{$base} is not an ancestor of {$head}");
            }
            $list = is_array($data['commits'] ?? null) ? $data['commits'] : [];
            foreach ($list as $commit) {
                if (is_array($commit)) {
                    $commits[] = self::sha(self::string($commit, 'sha'));
                }
            }
            if (count($list) < 100 || count($commits) >= self::int($data, 'total_commits')) {
                return $commits;
            }
        }

        throw new \RuntimeException('more than 10000 commits after the anchor; move the anchor forward');
    }

    public function workflowRuns(string $workflowPath, string $sha): array
    {
        $runs = [];
        $data = $this->call('GET', "/repos/{$this->repository}/actions/workflows/" . rawurlencode(basename($workflowPath)) . '/runs?per_page=100&head_sha=' . self::sha($sha))?->json() ?? [];
        foreach (is_array($data['workflow_runs'] ?? null) ? $data['workflow_runs'] : [] as $run) {
            if (!is_array($run)) {
                continue;
            }
            $runs[] = new WorkflowRun(
                self::int($run, 'id'),
                is_int($run['run_attempt'] ?? null) ? $run['run_attempt'] : 1,
                self::string($run, 'path'),
                self::string($run, 'event'),
                self::string($run, 'head_branch'),
                self::string($run, 'head_sha'),
                self::string(self::map($run, 'repository'), 'full_name'),
                self::string($run, 'status'),
                is_string($run['conclusion'] ?? null) ? $run['conclusion'] : null,
            );
        }

        return $runs;
    }

    public function jobConclusion(int $runId, string $jobName): ?string
    {
        foreach ($this->all("/repos/{$this->repository}/actions/runs/{$runId}/jobs?filter=latest&per_page=100", 'jobs') as $job) {
            if (($job['name'] ?? null) === $jobName) {
                return ($job['status'] ?? null) === 'completed' && is_string($job['conclusion'] ?? null) ? $job['conclusion'] : null;
            }
        }

        return null;
    }

    public function tags(): array
    {
        $tags = [];
        foreach ($this->all("/repos/{$this->repository}/git/matching-refs/tags?per_page=100") as $ref) {
            $name = substr(self::string($ref, 'ref'), strlen('refs/tags/'));
            $object = self::map($ref, 'object');
            $type = self::string($object, 'type');
            $sha = self::string($object, 'sha');
            $message = null;
            for ($depth = 0; $type === 'tag' && $depth < 3; $depth++) {
                $tag = $this->call('GET', "/repos/{$this->repository}/git/tags/" . self::sha($sha))?->json() ?? [];
                $message ??= self::string($tag, 'message');
                $object = self::map($tag, 'object');
                $type = self::string($object, 'type');
                $sha = self::string($object, 'sha');
            }
            if ($type === 'commit') {
                $tags[] = new Tag($name, self::sha($sha), $message);
            }
        }

        return $tags;
    }

    public function createAnnotatedTag(string $name, string $sha, string $message): void
    {
        $tag = $this->call('POST', "/repos/{$this->repository}/git/tags", body: ['tag' => $name, 'message' => $message, 'object' => self::sha($sha), 'type' => 'commit'])?->json() ?? [];
        $this->call('POST', "/repos/{$this->repository}/git/refs", body: ['ref' => "refs/tags/{$name}", 'sha' => self::sha(self::string($tag, 'sha'))]);
    }

    public function releaseByTag(string $tag): ?Release
    {
        $response = $this->call('GET', "/repos/{$this->repository}/releases/tags/" . rawurlencode($tag), allowMissing: true);

        return $response === null ? null : self::release($response->json());
    }

    public function createRelease(string $tag, string $name, string $body): Release
    {
        $response = $this->call('POST', "/repos/{$this->repository}/releases", body: ['tag_name' => $tag, 'name' => $name, 'body' => $body, 'draft' => false, 'prerelease' => false]);

        return self::release($response?->json() ?? []);
    }

    public function uploadAsset(Release $release, string $name, string $path): void
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException("cannot read {$path}");
        }
        $response = $this->http->request('POST', self::UPLOADS . "/repos/{$this->repository}/releases/{$release->id}/assets?name=" . rawurlencode($name), [...$this->headers(), 'Content-Type: application/octet-stream'], $contents);
        if ($response->status !== 201) {
            throw new \RuntimeException("uploading {$name} failed with HTTP {$response->status}");
        }
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function call(string $method, string $path, ?array $body = null, bool $allowMissing = false): ?Response
    {
        $response = $this->http->request($method, self::API . $path, $body === null ? $this->headers() : [...$this->headers(), 'Content-Type: application/json'], $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR));
        if ($allowMissing && $response->status === 404) {
            return null;
        }
        if ($response->status < 200 || $response->status >= 300) {
            $message = '';
            try {
                $data = $response->json();
                $message = is_string($data['message'] ?? null) ? ': ' . mb_substr($data['message'], 0, 200) : '';
            } catch (\JsonException) {
            }
            throw new \RuntimeException("GitHub API {$method} {$path} returned HTTP {$response->status}{$message}");
        }

        return $response;
    }

    /**
     * Every item of a paginated list, following Link rel="next" on api.github.com only.
     *
     * @return list<array<mixed>>
     */
    private function all(string $path, ?string $key = null): array
    {
        $items = [];
        $url = self::API . $path;
        for ($page = 0; $page < 100 && $url !== null; $page++) {
            $response = $this->http->request('GET', $url, $this->headers());
            if ($response->status !== 200) {
                throw new \RuntimeException("GitHub API GET {$path} returned HTTP {$response->status}");
            }
            $data = $response->json();
            $list = $key === null ? $data : (is_array($data[$key] ?? null) ? $data[$key] : []);
            foreach ($list as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }
            $url = null;
            if (preg_match('/<(https:\/\/api\.github\.com\/[^>]+)>;\s*rel="next"/', $response->headers['link'] ?? '', $m) === 1) {
                $url = $m[1];
            }
        }

        return $items;
    }

    /**
     * @return list<string>
     */
    private function headers(): array
    {
        return ['Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2022-11-28', 'Authorization: Bearer ' . $this->token];
    }

    /**
     * @param array<mixed> $data
     */
    private static function release(array $data): Release
    {
        $assets = [];
        foreach (is_array($data['assets'] ?? null) ? $data['assets'] : [] as $asset) {
            if (is_array($asset) && is_string($asset['name'] ?? null)) {
                $assets[] = $asset['name'];
            }
        }

        return new Release(self::int($data, 'id'), self::string($data, 'tag_name'), self::string($data, 'body'), $assets);
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    private static function map(array $data, string $key): array
    {
        return is_array($data[$key] ?? null) ? $data[$key] : [];
    }

    /**
     * @param array<mixed> $data
     */
    private static function string(array $data, string $key): string
    {
        return is_string($data[$key] ?? null) ? $data[$key] : '';
    }

    /**
     * @param array<mixed> $data
     */
    private static function int(array $data, string $key): int
    {
        return is_int($data[$key] ?? null) ? $data[$key] : 0;
    }

    private static function sha(string $sha): string
    {
        if (preg_match('/^[0-9a-f]{40}$/', $sha) !== 1) {
            throw new \RuntimeException('expected a full commit SHA from the API');
        }

        return $sha;
    }
}
