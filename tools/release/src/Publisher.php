<?php

declare(strict_types=1);

namespace ImagePuzzle\Release;

use ImagePuzzle\Release\GitHub\GitHub;
use ImagePuzzle\Release\GitHub\Release;

/**
 * Creates the tag and the GitHub Release of a plan, idempotently:
 *
 * - the publisher plans again from live data and refuses if the result
 *   differs from the plan the artifacts were built for;
 * - artifacts must match the accepted commit, the version and their
 *   checksums before anything is written;
 * - an existing tag must carry exactly this release's metadata at the
 *   accepted commit, otherwise the run fails without touching it;
 * - a missing release or missing assets are completed for the same tag, so
 *   an interrupted run is resumed by running it again.
 */
final class Publisher
{
    public function __construct(private readonly GitHub $github)
    {
    }

    /**
     * @return list<string> what was done, for the run summary
     */
    public function publish(Plan $planned, Plan $fresh, ?ArtifactSet $artifacts, bool $validatedInThisRun): array
    {
        $release = $fresh->release;
        if (!in_array($fresh->decision, [Plan::PUBLISH, Plan::NEEDS_VALIDATION], true) || $release === null) {
            throw new PolicyViolation("nothing to publish now: {$fresh->decision}: {$fresh->reason}");
        }
        $expected = $planned->release;
        if ($expected === null || $expected->pullRequest !== $release->pullRequest || $expected->acceptedSha !== $release->acceptedSha
            || (string) $expected->version !== (string) $release->version) {
            throw new PolicyViolation('the repository changed since this run was planned; a new run will plan again');
        }
        if ($fresh->decision === Plan::NEEDS_VALIDATION && !$validatedInThisRun) {
            throw new PolicyViolation("{$release->acceptedSha} has not passed validation");
        }
        $version = (string) $release->version;
        $artifacts?->verify($release->acceptedSha, $version);

        $done = [];
        $existing = null;
        foreach ($this->github->tags() as $tag) {
            if ($tag->name === $version) {
                $existing = $tag;
            }
        }
        if ($existing === null) {
            $this->github->createAnnotatedTag($version, $release->acceptedSha, $release->tagMessage());
            $done[] = "created tag {$version} at {$release->acceptedSha}";
        } else {
            $recorded = ReleaseMetadata::parse($existing->message);
            if ($existing->commit !== $release->acceptedSha || $recorded === null || $recorded->pullRequest !== $release->pullRequest || $recorded->acceptedSha !== $release->acceptedSha) {
                throw new PolicyViolation("tag {$version} already exists at {$existing->commit} for something else; it is left untouched");
            }
            $release = $recorded;
            $done[] = "tag {$version} already exists for #{$release->pullRequest}";
        }

        $published = $this->github->releaseByTag($version);
        if ($published === null) {
            $body = $fresh->notes . ($artifacts === null ? '' : $artifacts->notes()) . "\n" . $release->comment() . "\n";
            $published = $this->github->createRelease($version, $version, $body);
            $done[] = "created GitHub Release {$version}";
        } elseif (!str_contains($published->body, ReleaseMetadata::MARKER) || ReleaseMetadata::parse($published->body)?->pullRequest !== $release->pullRequest) {
            throw new PolicyViolation("a GitHub Release for {$version} exists without matching release metadata; it is left untouched");
        } else {
            $done[] = "GitHub Release {$version} already exists";
        }

        if ($artifacts !== null) {
            $done = [...$done, ...$this->uploadMissing($published, $artifacts)];
        }

        return $done;
    }

    /**
     * @return list<string>
     */
    private function uploadMissing(Release $release, ArtifactSet $artifacts): array
    {
        $done = [];
        foreach ($artifacts->files as $file) {
            if (!in_array($file['name'], $release->assets, true)) {
                $this->github->uploadAsset($release, $file['name'], $artifacts->path($file['name']));
                $done[] = "uploaded {$file['name']}";
            }
        }
        if (!in_array('SHA256SUMS', $release->assets, true)) {
            // Uploaded last: its presence marks a complete set of assets.
            $sums = tempnam(sys_get_temp_dir(), 'sums');
            if ($sums === false) {
                throw new \RuntimeException('cannot create a temporary file');
            }
            try {
                file_put_contents($sums, $artifacts->checksums());
                $this->github->uploadAsset($release, 'SHA256SUMS', $sums);
            } finally {
                unlink($sums);
            }
            $done[] = 'uploaded SHA256SUMS';
        }

        return $done;
    }
}
