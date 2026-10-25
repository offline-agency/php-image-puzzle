<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\ServiceProvider;
use ImagePuzzle\Livewire\ImagePuzzleServiceProvider;

/**
 * Demo application for `composer serve` and the browser tests: the offline
 * example definitions, plus one puzzle the gate always denies.
 */
final class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $assets = dirname(__DIR__, 5) . '/examples/player/assets';
        $config = $this->app->make(Config::class);
        $config->set('image-puzzle.puzzles', [
            'sliding' => $assets . '/sliding.json',
            'swap' => $assets . '/swap.json',
            'jigsaw' => $assets . '/jigsaw.json',
            'secret' => $assets . '/strips.json',
        ]);
        $config->set('image-puzzle.sync_debounce_ms', 300);
    }

    public function boot(): void
    {
        $this->app->make(Gate::class)->define(ImagePuzzleServiceProvider::ABILITY, static fn (mixed $user, string $puzzle): bool => $puzzle !== 'secret');
    }
}
