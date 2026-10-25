<?php

declare(strict_types=1);

namespace ImagePuzzle\Filament\Concerns;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use ImagePuzzle\Exception\InvalidDefinitionException;
use ImagePuzzle\Filament\Contracts\PuzzleRecord;
use ImagePuzzle\Game\BuildConfiguration;

/**
 * PuzzleRecord for Eloquent models. Columns (see PuzzleColumns::define()):
 * puzzle_source (path on the source disk), puzzle_config (JSON),
 * puzzle_definition (path below the configured generation directory),
 * puzzle_status and puzzle_error. Override the protected methods to use
 * other columns or another source disk; the disk must be local.
 *
 * @phpstan-require-extends \Illuminate\Database\Eloquent\Model
 * @phpstan-require-implements PuzzleRecord
 */
trait HasImagePuzzle
{
    protected function imagePuzzleSourceDisk(): string
    {
        return 'public';
    }

    protected function imagePuzzleSourceColumn(): string
    {
        return 'puzzle_source';
    }

    protected function imagePuzzleConfigurationColumn(): string
    {
        return 'puzzle_config';
    }

    protected function imagePuzzleDefinitionColumn(): string
    {
        return 'puzzle_definition';
    }

    protected function imagePuzzleStatusColumn(): string
    {
        return 'puzzle_status';
    }

    protected function imagePuzzleErrorColumn(): string
    {
        return 'puzzle_error';
    }

    public function imagePuzzleSourcePath(): ?string
    {
        $path = $this->getAttribute($this->imagePuzzleSourceColumn());
        if (!is_string($path) || $path === '' || str_contains($path, '..')) {
            return null;
        }
        $absolute = app(Filesystems::class)->disk($this->imagePuzzleSourceDisk())->path($path);

        return is_file($absolute) ? $absolute : null;
    }

    public function imagePuzzleConfiguration(): ?BuildConfiguration
    {
        $value = $this->getAttribute($this->imagePuzzleConfigurationColumn());
        if ($value === null || $value === '' || $value === []) {
            return null;
        }
        if (is_string($value)) {
            return BuildConfiguration::fromJson($value);
        }
        if (!is_array($value)) {
            throw new InvalidDefinitionException('The saved puzzle configuration is not a JSON object.');
        }

        return BuildConfiguration::fromArray($value);
    }

    public function imagePuzzleDefinitionPath(): ?string
    {
        $relative = $this->getAttribute($this->imagePuzzleDefinitionColumn());
        if (!is_string($relative) || $relative === '' || str_contains($relative, '..')) {
            return null;
        }

        return self::imagePuzzleRoot() . '/' . ltrim($relative, '/');
    }

    public function storeImagePuzzleDefinition(string $definitionPath): void
    {
        $root = self::imagePuzzleRoot() . '/';
        if (!str_starts_with($definitionPath, $root)) {
            throw new \InvalidArgumentException('Generated definitions must live in the configured image-puzzle-filament.directory.');
        }
        $this->forceFill([
            $this->imagePuzzleDefinitionColumn() => substr($definitionPath, strlen($root)),
            $this->imagePuzzleStatusColumn() => PuzzleRecord::STATUS_READY,
            $this->imagePuzzleErrorColumn() => null,
        ])->save();
    }

    public function imagePuzzleStatus(): ?string
    {
        $status = $this->getAttribute($this->imagePuzzleStatusColumn());

        return is_string($status) ? $status : null;
    }

    public function imagePuzzleError(): ?string
    {
        $error = $this->getAttribute($this->imagePuzzleErrorColumn());

        return is_string($error) ? $error : null;
    }

    public function markImagePuzzleStatus(string $status, ?string $error = null): void
    {
        $this->forceFill([
            $this->imagePuzzleStatusColumn() => $status,
            $this->imagePuzzleErrorColumn() => $error === null ? null : mb_substr($error, 0, 1000),
        ])->save();
    }

    private static function imagePuzzleRoot(): string
    {
        $root = app(Config::class)->get('image-puzzle-filament.directory');
        if (!is_string($root) || $root === '') {
            throw new \InvalidArgumentException('image-puzzle-filament.directory must be a directory path.');
        }

        return rtrim($root, '/');
    }
}
