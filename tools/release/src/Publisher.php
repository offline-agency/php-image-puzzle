<?php

declare(strict_types=1);

namespace ImagePuzzle\Release;

use ImagePuzzle\Release\GitHub\GitHub;
use ImagePuzzle\Release\GitHub\Release;
use ImagePuzzle\Release\GitHub\ReleaseAsset;
use ImagePuzzle\Release\GitHub\Tag;

/**
 * Creates the tag and the GitHub Release of a plan, idempotently:
 *
 * - a release that is already complete for the planned pull request,
 *   commit and version is left alone, so re-running a successful run is a
 *   no-op;
 * - otherwise the publisher plans again from live data and refuses if the
 *   result differs from the plan the artifacts were built for;
 * - artifacts must match the accepted commit, the version and their
 *   checksums before anything is written;
 * - an existing tag must carry exactly this release's metadata at the
 *   accepted commit, otherwise the run fails without touching it;
 * - a missing release or missing, unfinished or mismatching assets are
 *   completed for the same tag; SHA256SUMS is removed first and written
 *   last, so its presence always marks a checked, complete set.
 */
final class Publisher
{
    public const SUMS = 'SHA256SUMS';

    public function __construct(private readonly GitHub $github)
    {
    }

    /**
     * @return list<string> what was done, for the run summary
     */
    public function publish(Plan $planned, Plan $fresh, ?ArtifactSet $artifacts, bool $validatedInThisRun): array
    {
        $expected = $planned->release ?? throw new PolicyViolation('the plan has no release');
        $version = (string) $expected->version;
        $artifacts?->verify($expected->acceptedSha, $version);

        $existing = $this->tag($version);
        $published = $existing === null ? null : $this->github->releaseByTag($version);
        if ($existing !== null && $published !== null && $this->belongsTo($existing, $expected) && $this->releaseBelongsTo($published, $expected)
            && ($artifacts === null ? $published->complete() : $this->assetWork($published, $artifacts) === null)) {
            return ["{$version} is already published for #{$expected->pullRequest}; nothing to do"];
        }

        $release = $fresh->release;
        // A plan that waits for the push CI run is fine when this run's own
        // validation of the same commit passed.
        $publishable = $validatedInThisRun ? [Plan::PUBLISH, Plan::NEEDS_VALIDATION, Plan::WAIT] : [Plan::PUBLISH];
        if ($release === null || !in_array($fresh->decision, $publishable, true)) {
            throw new PolicyViolation("nothing to publish now: {$fresh->decision}: {$fresh->reason}");
        }
        if ($expected->pullRequest !== $release->pullRequest || $expected->acceptedSha !== $release->acceptedSha || $version !== (string) $release->version) {
            throw new PolicyViolation('the repository changed since this run was planned; a new run will plan again');
        }

        $done = [];
        if ($existing === null) {
            $this->github->createAnnotatedTag($version, $release->acceptedSha, $release->tagMessage());
            $done[] = "created tag {$version} at {$release->acceptedSha}";
        } elseif (!$this->belongsTo($existing, $release)) {
            throw new PolicyViolation("tag {$version} already exists at {$existing->commit} for something else; it is left untouched");
        } else {
            $release = ReleaseMetadata::parse($existing->message) ?? $release;
            $done[] = "tag {$version} already exists for #{$release->pullRequest}";
        }

        $published = $this->github->releaseByTag($version);
        if ($published === null) {
            $body = $fresh->notes . ($artifacts === null ? '' : $artifacts->notes()) . "\n" . $release->comment() . "\n";
            $published = $this->github->createRelease($version, $version, $body);
            $done[] = "created GitHub Release {$version}";
        } elseif (!$this->releaseBelongsTo($published, $release)) {
            throw new PolicyViolation("a GitHub Release for {$version} exists without matching release metadata; it is left untouched");
        } else {
            $done[] = "GitHub Release {$version} already exists";
        }

        if ($artifacts !== null) {
            $done = [...$done, ...$this->syncAssets($published, $artifacts)];
        }

        return $done;
    }

    private function tag(string $version): ?Tag
    {
        foreach ($this->github->tags() as $tag) {
            if ($tag->name === $version) {
                return $tag;
            }
        }

        return null;
    }

    private function belongsTo(Tag $tag, ReleaseMetadata $release): bool
    {
        $recorded = ReleaseMetadata::parse($tag->message);

        return $tag->commit === $release->acceptedSha && $recorded !== null && $recorded->pullRequest === $release->pullRequest
            && $recorded->acceptedSha === $release->acceptedSha && (string) $recorded->version === (string) $release->version;
    }

    private function releaseBelongsTo(Release $published, ReleaseMetadata $release): bool
    {
        $recorded = ReleaseMetadata::parse($published->body);

        return $recorded !== null && $recorded->pullRequest === $release->pullRequest && $recorded->acceptedSha === $release->acceptedSha;
    }

    /**
     * What it takes to make the assets equal the artifact set, or null when
     * they already do.
     *
     * @return array{delete: list<ReleaseAsset>, upload: list<string>, sums: bool}|null
     */
    private function assetWork(Release $published, ArtifactSet $artifacts): ?array
    {
        $delete = [];
        $upload = [];
        $wanted = [];
        foreach ($artifacts->files as $file) {
            $wanted[$file['name']] = true;
            $asset = $published->asset($file['name']);
            if ($asset === null || !$asset->matches($file['size'], $file['sha256'])) {
                $upload[] = $file['name'];
                if ($asset !== null) {
                    $delete[] = $asset;
                }
            }
        }
        foreach ($published->assets as $asset) {
            if ($asset->name === self::SUMS || isset($wanted[$asset->name])) {
                continue;
            }
            if ($asset->state === 'uploaded') {
                throw new PolicyViolation("release {$published->tagName} has an unexpected asset {$asset->name}; it is left untouched");
            }
            $delete[] = $asset;
        }
        $checksums = $artifacts->checksums();
        $sums = $published->asset(self::SUMS);
        $sumsCurrent = $sums !== null && $upload === [] && $sums->matches(strlen($checksums), hash('sha256', $checksums));
        if ($sums !== null && !$sumsCurrent) {
            // Removed first, so the release reads as incomplete until the end.
            array_unshift($delete, $sums);
        }

        return $delete === [] && $upload === [] && $sumsCurrent ? null : ['delete' => $delete, 'upload' => $upload, 'sums' => !$sumsCurrent];
    }

    /**
     * @return list<string>
     */
    private function syncAssets(Release $published, ArtifactSet $artifacts): array
    {
        $work = $this->assetWork($published, $artifacts);
        if ($work === null) {
            return ['every asset is already uploaded and verified'];
        }
        $done = [];
        foreach ($work['delete'] as $asset) {
            $this->github->deleteAsset($published, $asset);
            $done[] = "removed {$asset->state} asset {$asset->name}";
        }
        foreach ($work['upload'] as $name) {
            $this->github->uploadAsset($published, $name, $artifacts->path($name));
            $done[] = "uploaded {$name}";
        }
        if ($work['sums']) {
            $sums = tempnam(sys_get_temp_dir(), 'sums');
            if ($sums === false) {
                throw new \RuntimeException('cannot create a temporary file');
            }
            try {
                file_put_contents($sums, $artifacts->checksums());
                $this->github->uploadAsset($published, self::SUMS, $sums);
            } finally {
                unlink($sums);
            }
            $done[] = 'uploaded ' . self::SUMS;
        }

        return $done;
    }
}
