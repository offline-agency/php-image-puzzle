<?php

declare(strict_types=1);

namespace Workbench\App\Filament\Resources\Puzzles\Pages;

use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use ImagePuzzle\Filament\Actions\DownloadPuzzleAction;
use ImagePuzzle\Filament\Actions\GeneratePuzzleAction;
use Workbench\App\Filament\Resources\Puzzles\PuzzleResource;

/**
 * Read-only page: users who may view but not update a puzzle can play and
 * download it, while generation stays hidden.
 */
final class ViewPuzzle extends ViewRecord
{
    protected static string $resource = PuzzleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GeneratePuzzleAction::make(),
            DownloadPuzzleAction::make(),
            EditAction::make(),
        ];
    }
}
