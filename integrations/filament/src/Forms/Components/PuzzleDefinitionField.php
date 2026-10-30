<?php

declare(strict_types=1);

namespace ImagePuzzle\Filament\Forms\Components;

use Filament\Forms\Components\Field;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Model;
use ImagePuzzle\Exception\ImagePuzzleException;
use ImagePuzzle\Filament\Contracts\PuzzleRecord;
use ImagePuzzle\Filament\PuzzleModels;
use ImagePuzzle\Filament\Rules\ValidBuildConfiguration;
use ImagePuzzle\Game\BuildConfiguration;
use ImagePuzzle\Game\GameKind;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Form field that edits a puzzle build configuration with the shared
 * <image-puzzle-configurator>: mode, difficulty, custom parameters, seed and
 * crop over a preview of the source image. The state is the configuration
 * document (an array; cast the column to array or json), validated with the
 * PHP rules on the server.
 *
 *     PuzzleDefinitionField::make('puzzle_config')->sourceField('puzzle_source')->kinds(['jigsaw', 'swap'])
 */
class PuzzleDefinitionField extends Field
{
    protected string $view = 'image-puzzle-filament::forms.puzzle-definition-field';

    /** @var list<string>|\Closure */
    protected array | \Closure $kinds = [];

    protected string | \Closure | null $imageUrl = null;

    protected string | \Closure | null $sourceField = null;

    protected string | \Closure | null $locale = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->default(null);

        $this->afterStateHydrated(static function (PuzzleDefinitionField $component, mixed $state): void {
            if (is_string($state)) {
                $decoded = json_decode($state, true);
                $component->state(is_array($decoded) ? $decoded : null);
            }
        });

        $this->rule(static fn (PuzzleDefinitionField $component): ValidBuildConfiguration => new ValidBuildConfiguration($component->getKinds()));

        // Store the normalised document; validation has already rejected anything else.
        $this->dehydrateStateUsing(static function (mixed $state): mixed {
            if ($state === null || $state === []) {
                return null;
            }
            try {
                return BuildConfiguration::fromArray($state)->toArray();
            } catch (ImagePuzzleException) {
                return $state;
            }
        });
    }

    /**
     * @param list<string|GameKind>|\Closure $kinds modes offered, in order
     */
    public function kinds(array | \Closure $kinds): static
    {
        $this->kinds = $kinds instanceof \Closure
            ? $kinds
            : array_values(array_map(static fn (string | GameKind $kind): string => $kind instanceof GameKind ? $kind->value : $kind, $kinds));

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getKinds(): array
    {
        $kinds = $this->evaluate($this->kinds);
        if (!is_array($kinds)) {
            return [];
        }
        $values = [];
        foreach ($kinds as $kind) {
            if ($kind instanceof GameKind) {
                $values[] = $kind->value;
            } elseif (is_string($kind)) {
                $values[] = $kind;
            }
        }

        return $values;
    }

    /** Preview image URL; overrides sourceField(). */
    public function imageUrl(string | \Closure | null $url): static
    {
        $this->imageUrl = $url;

        return $this;
    }

    /**
     * Name of the sibling FileUpload that holds the source image: a new upload
     * previews through Livewire's signed temporary URL, a saved image through
     * the authorized record source route.
     */
    public function sourceField(string | \Closure | null $name): static
    {
        $this->sourceField = $name;

        return $this;
    }

    public function locale(string | \Closure | null $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    public function getLocale(): string
    {
        $locale = $this->evaluate($this->locale);

        return is_string($locale) && $locale !== '' ? $locale : app()->getLocale();
    }

    public function getImageUrl(): ?string
    {
        if ($this->imageUrl !== null) {
            $url = $this->evaluate($this->imageUrl);

            return is_string($url) && $url !== '' ? $url : null;
        }
        $field = $this->evaluate($this->sourceField);
        if (!is_string($field) || $field === '') {
            return null;
        }
        $state = $this->evaluate(static fn (Get $get): mixed => $get($field));
        $file = is_array($state) ? (array_values($state)[0] ?? null) : $state;
        if ($file instanceof TemporaryUploadedFile) {
            $url = $file->isPreviewable() ? $file->temporaryUrl() : null;

            return is_string($url) ? $url : null;
        }
        // A saved path: only the record's own source is served, through the authorized route.
        $record = $this->getRecord();
        if (is_string($file) && $file !== '' && $record instanceof Model && $record instanceof PuzzleRecord && $record->exists) {
            return route('image-puzzle-filament.source', ['puzzle' => app(PuzzleModels::class)->keyFor($record), 'v' => substr(hash('sha256', $file), 0, 12)]);
        }

        return null;
    }
}
