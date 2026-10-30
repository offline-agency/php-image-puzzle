<?php

declare(strict_types=1);

namespace ImagePuzzle\Filament;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use ImagePuzzle\Filament\Http\SourceImageController;
use ImagePuzzle\Game\DefinitionBuilder;
use ImagePuzzle\Game\GameFactory;
use ImagePuzzle\Livewire\Contracts\PuzzleRepository;
use ImagePuzzle\ResourceLimits;

final class ImagePuzzleFilamentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/image-puzzle-filament.php', 'image-puzzle-filament');

        $this->app->singleton(PuzzleModels::class, static function (Application $app): PuzzleModels {
            $config = $app->make(Config::class);
            $models = $config->get('image-puzzle-filament.models', []);
            $directory = $config->get('image-puzzle-filament.directory');
            /** @var array<string, class-string<\Illuminate\Database\Eloquent\Model&Contracts\PuzzleRecord>> $models */
            $models = is_array($models) ? $models : [];

            return new PuzzleModels($models, is_string($directory) && $directory !== '' ? $directory : $app->storagePath('app/image-puzzles'));
        });

        $this->app->singleton(PuzzleGeneration::class, static function (Application $app): PuzzleGeneration {
            $limits = $app->make(Config::class)->get('image-puzzle-filament.limits', []);
            $limits = is_array($limits) ? $limits : [];
            $value = static fn (string $key, int $default): int => is_int($limits[$key] ?? null) ? $limits[$key] : $default;

            return new PuzzleGeneration($app->make(PuzzleModels::class), new DefinitionBuilder(new GameFactory(), new ResourceLimits(
                maxInputBytes: $value('max_input_bytes', ResourceLimits::DEFAULT_MAX_INPUT_BYTES),
                maxPixels: $value('max_pixels', ResourceLimits::DEFAULT_MAX_PIXELS),
                maxPieces: $value('max_pieces', ResourceLimits::DEFAULT_MAX_PIECES),
            )));
        });

        // Record keys ("<alias>.<id>") resolve to records; other keys keep the previous repository.
        $this->app->extend(PuzzleRepository::class, static fn (PuzzleRepository $repository, Application $app): PuzzleRepository => new RecordPuzzleRepository($app->make(PuzzleModels::class), $repository));
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'image-puzzle-filament');
        $this->loadTranslationsFrom(__DIR__ . '/../resources/lang', 'image-puzzle-filament');
        $this->publishes([__DIR__ . '/../config/image-puzzle-filament.php' => $this->app->configPath('image-puzzle-filament.php')], 'image-puzzle-filament-config');
        $this->publishes([__DIR__ . '/../resources/lang' => $this->app->langPath('vendor/image-puzzle-filament')], 'image-puzzle-filament-translations');

        $config = $this->app->make(Config::class);
        $prefix = $config->get('image-puzzle.route_prefix', 'image-puzzle');
        $middleware = $config->get('image-puzzle.middleware', ['web']);
        Route::middleware(is_array($middleware) ? $middleware : ['web'])
            ->prefix(is_string($prefix) ? $prefix : 'image-puzzle')
            ->group(static function (): void {
                Route::get('records/{puzzle}/source', SourceImageController::class)
                    ->where('puzzle', '[a-z][a-z0-9_-]{0,31}\.[A-Za-z0-9_-]{1,64}')
                    ->name('image-puzzle-filament.source');
            });
    }
}
