<?php

declare(strict_types=1);

namespace ImagePuzzle\Livewire;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use ImagePuzzle\Livewire\Contracts\PuzzleRepository;
use ImagePuzzle\Livewire\Contracts\StateStore;
use ImagePuzzle\Livewire\Http\PuzzleController;
use Livewire\Livewire;

final class ImagePuzzleServiceProvider extends ServiceProvider
{
    /** Gate ability checked with (user|null, puzzle key) before any play, save or asset access. */
    public const ABILITY = 'image-puzzle.play';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/image-puzzle.php', 'image-puzzle');

        $this->app->singleton(PuzzleRepository::class, static function (Application $app): PuzzleRepository {
            $puzzles = $app->make(Config::class)->get('image-puzzle.puzzles', []);

            return new ConfigPuzzleRepository(is_array($puzzles) ? $puzzles : []);
        });
        $this->app->bind(StateStore::class, static fn (Application $app): StateStore => new SessionStateStore($app->make(Session::class)));
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'image-puzzle');
        $this->publishes([__DIR__ . '/../config/image-puzzle.php' => $this->app->configPath('image-puzzle.php')], 'image-puzzle-config');

        $gate = $this->app->make(Gate::class);
        if (!$gate->has(self::ABILITY)) {
            // Registered puzzles are public by default; define the ability to restrict them.
            $gate->define(self::ABILITY, static fn (mixed $user, string $puzzle): bool => true);
        }

        Livewire::component('image-puzzle-player', PuzzlePlayer::class);

        $config = $this->app->make(Config::class);
        $prefix = $config->get('image-puzzle.route_prefix', 'image-puzzle');
        $middleware = $config->get('image-puzzle.middleware', ['web']);
        Route::prefix(is_string($prefix) ? $prefix : 'image-puzzle')
            ->middleware(is_array($middleware) ? $middleware : ['web'])
            ->name('image-puzzle.')
            ->group(static function (): void {
                Route::get('player.js', [PuzzleController::class, 'script'])->name('script');
                Route::get('puzzles/{puzzle}/definition.json', [PuzzleController::class, 'definition'])
                    ->where('puzzle', '[A-Za-z0-9][A-Za-z0-9._-]{0,63}')
                    ->name('definition');
                Route::get('puzzles/{puzzle}/{path}', [PuzzleController::class, 'asset'])
                    ->where('puzzle', '[A-Za-z0-9][A-Za-z0-9._-]{0,63}')
                    ->where('path', '[A-Za-z0-9_][A-Za-z0-9._-]*(/[A-Za-z0-9_][A-Za-z0-9._-]*)*')
                    ->name('asset');
            });
    }
}
