<?php

declare(strict_types=1);

namespace ImagePuzzle\Filament\Contracts;

use ImagePuzzle\Game\BuildConfiguration;

/**
 * A host record that owns one puzzle: its source image, its saved build
 * configuration and the generated definition. The HasImagePuzzle trait
 * implements it for Eloquent models with a few columns.
 *
 * The saved configuration is authoring data; players' progress is stored
 * separately by the Livewire StateStore and never written to the record.
 */
interface PuzzleRecord
{
    /** Status values of a generation. */
    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_READY = 'ready';
    public const STATUS_FAILED = 'failed';

    /** Absolute local path of the source image, or null when none is set. */
    public function imagePuzzleSourcePath(): ?string;

    /** The saved build configuration, or null when none is set. */
    public function imagePuzzleConfiguration(): ?BuildConfiguration;

    /** Absolute path of the generated definition.json, or null before the first generation. */
    public function imagePuzzleDefinitionPath(): ?string;

    /** Records a finished generation: the absolute path of its definition.json. */
    public function storeImagePuzzleDefinition(string $definitionPath): void;

    public function imagePuzzleStatus(): ?string;

    public function imagePuzzleError(): ?string;

    public function markImagePuzzleStatus(string $status, ?string $error = null): void;
}
