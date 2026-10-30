<?php

declare(strict_types=1);

namespace ImagePuzzle\Filament\Tests;

use Filament\Facades\Filament;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ImagePuzzle\Filament\PuzzleGeneration;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as Orchestra;
use Workbench\App\Models\Puzzle;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;
    use WithWorkbench;

    protected string $generations = '';

    protected function setUp(): void
    {
        $this->generations = sys_get_temp_dir() . '/image-puzzle-filament-' . Str::random(12);
        parent::setUp();
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        PuzzleGeneration::remove($this->generations);
        parent::tearDown();
    }

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        $config = $app->make(Config::class);
        $config->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        $config->set('database.default', 'testing');
        $config->set('image-puzzle-filament.directory', $this->generations);
        $config->set('image-puzzle-filament.queue', false);
    }

    /**
     * A team with one member, signed in to the panel on that team.
     *
     * @return array{Team, User}
     */
    protected function signIn(string $slug = 'acme'): array
    {
        $team = Team::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $user = User::factory()->create();
        $team->users()->attach($user);
        $this->actingAs($user);
        Filament::setCurrentPanel('admin');
        Filament::setTenant($team);
        // What the panel middleware does: registers the tenant scope and creation observer.
        Filament::bootCurrentPanel();

        return [$team, $user];
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function puzzleFor(Team $team, array $config = [], bool $withSource = true): Puzzle
    {
        if ($withSource) {
            Storage::disk('public')->put('puzzle-sources/source.png', self::png(120, 90));
        }

        return Puzzle::query()->create([
            'team_id' => $team->id,
            'name' => 'Autumn',
            'puzzle_source' => $withSource ? 'puzzle-sources/source.png' : null,
            'puzzle_config' => $config + self::config(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function config(string $kind = 'swap', int $seed = 7): array
    {
        return ['format' => 'image-puzzle/build-config', 'version' => 1, 'kind' => $kind, 'preset' => 'easy', 'seed' => $seed];
    }

    /**
     * @param positive-int $width
     * @param positive-int $height
     */
    protected static function png(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        for ($x = 0; $x < $width; $x += 10) {
            imagefilledrectangle($image, $x, 0, $x + 9, $height - 1, (int) imagecolorallocate($image, ($x * 2) % 256, 128, 64));
        }
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
