<?php

declare(strict_types=1);

namespace Workbench\App\Filament\Resources\Puzzles\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Workbench\App\Filament\Resources\Puzzles\PuzzleResource;

final class ListPuzzles extends ListRecords
{
    protected static string $resource = PuzzleResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
