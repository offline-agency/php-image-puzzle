<?php

declare(strict_types=1);

namespace ImagePuzzle\Filament\Tests\Fixtures;

use Filament\Forms\Components\Repeater;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use ImagePuzzle\Filament\Forms\Components\PuzzleDefinitionField;
use Livewire\Component;

/**
 * Several configurators in one form: a repeater inside tabs, a mode
 * restriction and a disabled instance.
 */
final class RepeatedPuzzles extends Component implements HasSchemas
{
    use InteractsWithSchemas;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** @var array<string, mixed>|null */
    public ?array $saved = null;

    public function mount(): void
    {
        $this->schema()->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('Puzzles')->tabs([
                    Tab::make('Rounds')->schema([
                        Repeater::make('rounds')->schema([
                            PuzzleDefinitionField::make('config')->kinds(['swap', 'sliding'])->required(),
                        ]),
                    ]),
                    Tab::make('Locked')->schema([
                        PuzzleDefinitionField::make('locked')->disabled(),
                    ]),
                ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $this->saved = $this->schema()->getState();
    }

    private function schema(): Schema
    {
        return $this->getSchema('form') ?? throw new \LogicException('The form schema is missing.');
    }

    public function render(): string
    {
        return '<div>{{ $this->form }}</div>';
    }
}
