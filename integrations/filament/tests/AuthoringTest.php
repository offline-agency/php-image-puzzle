<?php

declare(strict_types=1);

namespace ImagePuzzle\Filament\Tests;

use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Workbench\App\Filament\Resources\Puzzles\Pages\CreatePuzzle;
use Workbench\App\Filament\Resources\Puzzles\Pages\EditPuzzle;
use Workbench\App\Models\Puzzle;

final class AuthoringTest extends TestCase
{
    public function testCreatesARecordWithANormalisedConfiguration(): void
    {
        [$team] = $this->signIn();

        Livewire::test(CreatePuzzle::class)
            ->fillForm([
                'name' => 'Autumn',
                'puzzle_source' => UploadedFile::fake()->createWithContent('autumn.png', self::png(120, 90)),
                'puzzle_config' => ['kind' => 'jigsaw', 'preset' => 'easy', 'seed' => 12, 'format' => 'image-puzzle/build-config', 'version' => 1, 'crop' => ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 80]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $puzzle = Puzzle::query()->sole();
        self::assertSame($team->id, $puzzle->team_id);
        self::assertSame(
            ['format' => 'image-puzzle/build-config', 'version' => 1, 'kind' => 'jigsaw', 'preset' => 'easy', 'seed' => 12, 'crop' => ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 80]],
            $puzzle->puzzle_config,
        );
        self::assertNotNull($puzzle->imagePuzzleSourcePath());
        self::assertNull($puzzle->puzzle_definition, 'Saving a configuration must not generate the puzzle.');
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidConfigurations(): iterable
    {
        yield 'unknown kind' => [['kind' => 'tetris', 'preset' => 'easy', 'seed' => 1]];
        yield 'negative seed' => [['kind' => 'swap', 'preset' => 'easy', 'seed' => -1]];
        yield 'jigsaw seed above the jigsaw bound' => [['kind' => 'jigsaw', 'preset' => 'easy', 'seed' => 2147483648]];
        yield 'custom keys without the custom preset' => [['kind' => 'swap', 'preset' => 'easy', 'seed' => 1, 'custom' => ['columns' => 3]]];
        yield 'zero-sized crop' => [['kind' => 'swap', 'preset' => 'easy', 'seed' => 1, 'crop' => ['x' => 0, 'y' => 0, 'width' => 0, 'height' => 10]]];
        yield 'unknown member' => [['kind' => 'swap', 'preset' => 'easy', 'seed' => 1, 'extra' => true]];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidConfigurations')]
    public function testRejectsInvalidConfigurations(array $config): void
    {
        $this->signIn();

        Livewire::test(CreatePuzzle::class)
            ->fillForm([
                'name' => 'Autumn',
                'puzzle_source' => UploadedFile::fake()->createWithContent('autumn.png', self::png(60, 40)),
                'puzzle_config' => $config + ['format' => 'image-puzzle/build-config', 'version' => 1],
            ])
            ->call('create')
            ->assertHasFormErrors(['puzzle_config']);

        self::assertSame(0, Puzzle::query()->count());
    }

    public function testRequiresAConfiguration(): void
    {
        $this->signIn();

        Livewire::test(CreatePuzzle::class)
            ->fillForm(['name' => 'Autumn', 'puzzle_source' => UploadedFile::fake()->createWithContent('a.png', self::png(60, 40)), 'puzzle_config' => null])
            ->call('create')
            ->assertHasFormErrors(['puzzle_config' => 'required']);
    }

    public function testEditLoadsSavesAndReloadsTheConfiguration(): void
    {
        [$team] = $this->signIn();
        $puzzle = $this->puzzleFor($team);

        Livewire::test(EditPuzzle::class, ['record' => $puzzle->getRouteKey()])
            ->assertSchemaStateSet(['puzzle_config' => self::config()])
            ->fillForm(['puzzle_config' => self::config('rotation', 99)])
            ->call('save')
            ->assertHasNoFormErrors();

        $puzzle->refresh();
        self::assertSame(self::config('rotation', 99), $puzzle->puzzle_config);

        Livewire::test(EditPuzzle::class, ['record' => $puzzle->getRouteKey()])
            ->assertSchemaStateSet(['puzzle_config' => self::config('rotation', 99)]);
    }

    public function testHydratesAConfigurationStoredAsAJsonString(): void
    {
        [$team] = $this->signIn();
        $puzzle = $this->puzzleFor($team);
        $puzzle->getConnection()->table('puzzles')->where('id', $puzzle->id)
            ->update(['puzzle_config' => json_encode(json_encode(self::config('strips', 3)))]);

        Livewire::test(EditPuzzle::class, ['record' => $puzzle->getRouteKey()])
            ->assertSchemaStateSet(['puzzle_config' => self::config('strips', 3)]);
    }

    public function testTheFieldRendersTheConfiguratorWithTheSavedSourceImage(): void
    {
        [$team] = $this->signIn();
        $puzzle = $this->puzzleFor($team);

        Livewire::test(EditPuzzle::class, ['record' => $puzzle->getRouteKey()])
            ->assertSeeHtml('<image-puzzle-configurator')
            ->assertSeeHtml('theme-class="dark"')
            ->assertSeeHtml(e(route('image-puzzle-filament.source', ['puzzle' => 'puzzle.' . $puzzle->id])));
    }

    public function testAnotherTeamsRecordIsNotReachableFromThePanel(): void
    {
        [$other] = $this->signIn('globex');
        $foreign = $this->puzzleFor($other);
        $this->signIn('acme');

        Livewire::test(EditPuzzle::class, ['record' => $foreign->getRouteKey()])->assertNotFound();
        self::assertSame(0, Puzzle::query()->count(), 'The tenant scope hides the other team\'s records.');
    }
}
