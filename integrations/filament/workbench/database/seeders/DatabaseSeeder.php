<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Workbench\App\Models\Puzzle;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/**
 * Two teams with one author each, so the cross-tenant denials can be
 * exercised by hand and by the browser tests. Demo credentials only.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $picture = dirname(__DIR__, 5) . '/examples/player/assets/picture.png';
        Storage::disk('public')->put('puzzle-sources/picture.png', (string) file_get_contents($picture));

        foreach (['acme' => 'author@example.test', 'globex' => 'other@example.test'] as $slug => $email) {
            $team = Team::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
            $user = User::query()->create(['name' => ucfirst($slug) . ' author', 'email' => $email, 'password' => 'password']);
            $team->users()->attach($user);
            Puzzle::query()->create([
                'team_id' => $team->id,
                'name' => ucfirst($slug) . ' puzzle',
                'puzzle_source' => 'puzzle-sources/picture.png',
                'puzzle_config' => ['format' => 'image-puzzle/build-config', 'version' => 1, 'kind' => 'swap', 'preset' => 'easy', 'seed' => 7],
            ]);
        }
    }
}
