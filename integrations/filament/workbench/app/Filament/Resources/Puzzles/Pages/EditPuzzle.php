<?php

declare(strict_types=1);

namespace Workbench\App\Filament\Resources\Puzzles\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use ImagePuzzle\Filament\Actions\DownloadPuzzleAction;
use ImagePuzzle\Filament\Actions\GeneratePuzzleAction;
use Workbench\App\Filament\Resources\Puzzles\PuzzleResource;

final class EditPuzzle extends EditRecord
{
    protected static string $resource = PuzzleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GeneratePuzzleAction::make()->after(fn () => $this->refreshFormData([])),
            DownloadPuzzleAction::make(),
            DeleteAction::make(),
        ];
    }
}
