<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Models\Puzzle;
use Workbench\App\Policies\PuzzlePolicy;

/**
 * Demo application for `composer serve`, the tests and the browser tests:
 * a team-scoped Filament panel whose Puzzle resource uses the authoring
 * components, plus a configured example puzzle on the dashboard.
 */
final class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $config = $this->app->make(Config::class);
        $config->set('image-puzzle-filament.models', ['puzzle' => Puzzle::class]);
        $config->set('image-puzzle.puzzles', [
            'swap' => dirname(__DIR__, 5) . '/examples/player/assets/swap.json',
        ]);
        $config->set('image-puzzle.sync_debounce_ms', 300);
    }

    public function boot(): void
    {
        $this->app->make(Gate::class)->policy(Puzzle::class, PuzzlePolicy::class);
    }
}
