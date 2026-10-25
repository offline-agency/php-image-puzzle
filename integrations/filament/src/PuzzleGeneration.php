<?php

declare(strict_types=1);

namespace ImagePuzzle\Filament;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use ImagePuzzle\Exception\ImagePuzzleException;
use ImagePuzzle\Filament\Contracts\PuzzleRecord;
use ImagePuzzle\Game\BuildResult;
use ImagePuzzle\Game\DefinitionBuilder;

/**
 * Generates a record's puzzle into a new, immutable directory below the
 * record's own generation directory, then points the record at it and
 * removes the generation it replaced. A failed build leaves the previous
 * puzzle playable.
 */
final readonly class PuzzleGeneration
{
    public function __construct(
        private PuzzleModels $models,
        private DefinitionBuilder $builder,
    ) {
    }

    /**
     * @throws ImagePuzzleException|\InvalidArgumentException with a message that can be shown to the author
     */
    public function generate(Model&PuzzleRecord $record): BuildResult
    {
        $config = $record->imagePuzzleConfiguration();
        if ($config === null) {
            throw new \InvalidArgumentException('Save a puzzle configuration before generating the puzzle.');
        }
        $source = $record->imagePuzzleSourcePath();
        if ($source === null) {
            throw new \InvalidArgumentException('Upload a source image before generating the puzzle.');
        }
        $base = $this->models->directoryFor($record);
        $directory = $base . '/g-' . gmdate('YmdHis') . '-' . Str::random(12);
        try {
            $result = $this->builder->buildConfigured($source, $directory, $config);
            $previous = $record->getConnection()->transaction(static function () use ($record, $result): ?string {
                $fresh = $record->newQuery()->lockForUpdate()->whereKey($record->getKey())->first();
                $previous = $fresh instanceof PuzzleRecord ? $fresh->imagePuzzleDefinitionPath() : null;
                $record->storeImagePuzzleDefinition($result->definitionPath());

                return $previous;
            });
        } catch (\Throwable $e) {
            self::remove($directory);
            throw $e;
        }
        if ($previous !== null && dirname($previous) !== $directory && str_starts_with($previous, $base . '/')) {
            self::remove(dirname($previous));
        }

        return $result;
    }

    /** Removes a generation directory (and everything in it). */
    public static function remove(string $directory): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            if ($item instanceof \SplFileInfo) {
                $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
        }
        @rmdir($directory);
    }
}
