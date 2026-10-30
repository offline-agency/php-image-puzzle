<?php

declare(strict_types=1);

namespace Workbench\App\Filament\Resources\Puzzles;

use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use ImagePuzzle\Filament\Forms\Components\PuzzleDefinitionField;
use ImagePuzzle\Filament\Schemas\Components\PuzzlePreview;
use Workbench\App\Filament\Resources\Puzzles\Pages\CreatePuzzle;
use Workbench\App\Filament\Resources\Puzzles\Pages\EditPuzzle;
use Workbench\App\Filament\Resources\Puzzles\Pages\ListPuzzles;
use Workbench\App\Filament\Resources\Puzzles\Pages\ViewPuzzle;
use Workbench\App\Models\Puzzle;

/**
 * The authoring flow: source image -> crop and settings -> preview ->
 * generate (header action) -> play -> download.
 */
final class PuzzleResource extends Resource
{
    protected static ?string $model = Puzzle::class;

    protected static string | \BackedEnum | null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(120),
            FileUpload::make('puzzle_source')
                ->label('Source image')
                ->image()
                ->disk('public')
                ->directory('puzzle-sources')
                ->maxSize(8192)
                ->live()
                ->required(),
            PuzzleDefinitionField::make('puzzle_config')
                ->label('Puzzle')
                ->sourceField('puzzle_source')
                ->required(),
            Section::make('Play')
                ->schema([PuzzlePreview::make()])
                ->visibleOn(['edit', 'view']),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('puzzle_status')->label('Status')->badge(),
                TextColumn::make('updated_at')->dateTime(),
            ])
            ->recordActions([ViewAction::make(), EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPuzzles::route('/'),
            'create' => CreatePuzzle::route('/create'),
            'view' => ViewPuzzle::route('/{record}'),
            'edit' => EditPuzzle::route('/{record}/edit'),
        ];
    }
}
