<?php

declare(strict_types=1);

namespace ImagePuzzle\Release;

/**
 * A stable release version. Tags are unprefixed MAJOR.MINOR.PATCH (`1.0.2`);
 * prereleases, build metadata and `v`-prefixed names are not release tags.
 */
final readonly class Version
{
    private const PATTERN = '/^(0|[1-9]\d{0,5})\.(0|[1-9]\d{0,5})\.(0|[1-9]\d{0,5})$/';

    public function __construct(
        public int $major,
        public int $minor,
        public int $patch,
    ) {
    }

    public static function parse(string $tag): ?self
    {
        if (preg_match(self::PATTERN, $tag, $m) !== 1) {
            return null;
        }

        return new self((int) $m[1], (int) $m[2], (int) $m[3]);
    }

    /** Before the first release tag. */
    public static function initial(): self
    {
        return new self(0, 0, 0);
    }

    /**
     * The next version. From 1.0.0 on this is plain SemVer. Without any
     * release (0.0.0) the first version is 0.1.0. Below 1.0.0 a breaking
     * change raises the minor and anything else the patch; only an explicit
     * release:major label leaves 0.x (to 1.0.0).
     */
    public function bump(Bump $bump, bool $explicitMajor = false): self
    {
        if ($this->major === 0) {
            if ($bump === Bump::Major && $explicitMajor) {
                return new self(1, 0, 0);
            }
            if ($bump === Bump::Major || ($this->minor === 0 && $this->patch === 0)) {
                return new self(0, $this->minor + 1, 0);
            }

            return new self(0, $this->minor, $this->patch + 1);
        }

        return match ($bump) {
            Bump::Major => new self($this->major + 1, 0, 0),
            Bump::Minor => new self($this->major, $this->minor + 1, 0),
            Bump::Patch => new self($this->major, $this->minor, $this->patch + 1),
        };
    }

    public function compare(self $other): int
    {
        return [$this->major, $this->minor, $this->patch] <=> [$other->major, $other->minor, $other->patch];
    }

    public function __toString(): string
    {
        return "{$this->major}.{$this->minor}.{$this->patch}";
    }
}
