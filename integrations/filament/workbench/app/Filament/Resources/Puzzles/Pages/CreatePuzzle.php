<?php

declare(strict_types=1);

namespace Workbench\App\Filament\Resources\Puzzles\Pages;

use Filament\Resources\Pages\CreateRecord;
use Workbench\App\Filament\Resources\Puzzles\PuzzleResource;

final class CreatePuzzle extends CreateRecord
{
    protected static string $resource = PuzzleResource::class;
}
