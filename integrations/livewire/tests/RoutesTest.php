<?php

declare(strict_types=1);

namespace ImagePuzzle\Livewire\Tests;

use Illuminate\Contracts\Auth\Access\Gate;
use ImagePuzzle\Livewire\ImagePuzzleServiceProvider;
use ImagePuzzle\Livewire\Puzzles;

final class RoutesTest extends TestCase
{
    private function denyAll(): void
    {
        $this->app?->make(Gate::class)->define(ImagePuzzleServiceProvider::ABILITY, static fn (mixed $user, string $puzzle): bool => false);
    }

    public function test_serves_the_player_script_with_cache_headers_tied_to_its_content(): void
    {
        $this->get(route('image-puzzle.script', ['v' => Puzzles::scriptVersion()]))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/javascript; charset=utf-8')
            ->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');
        $this->get(route('image-puzzle.script'))->assertOk()->assertHeader('Cache-Control', 'max-age=300, public');
    }

    public function test_serves_the_definition_to_authorized_visitors_only(): void
    {
        $definition = self::puzzle('jigsaw')->definition;
        $this->get(route('image-puzzle.definition', ['puzzle' => 'jigsaw']))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('id', $definition->id);
        $this->get('/image-puzzle/puzzles/unknown/definition.json')->assertNotFound();

        $this->denyAll();
        $this->get(route('image-puzzle.definition', ['puzzle' => 'jigsaw']))->assertForbidden();
    }

    public function test_serves_exactly_the_assets_the_definition_references(): void
    {
        $piece = self::puzzle('jigsaw')->definition->tiles[0]->attributes['image'];
        self::assertIsString($piece);

        $this->get('/image-puzzle/puzzles/jigsaw/source.png')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get('/image-puzzle/puzzles/jigsaw/' . $piece)->assertOk();
        // Unreferenced files in the puzzle directory and traversal attempts.
        $this->get('/image-puzzle/puzzles/jigsaw/pieces/manifest.json')->assertNotFound();
        $this->get('/image-puzzle/puzzles/jigsaw/..%2F..%2Fsecret.png')->assertNotFound();
        $this->get('/image-puzzle/puzzles/jigsaw/pieces/../source.png')->assertNotFound();

        $this->denyAll();
        $this->get('/image-puzzle/puzzles/jigsaw/source.png')->assertForbidden();
    }

    public function test_the_bundled_script_matches_the_built_player_package(): void
    {
        $built = dirname(__DIR__, 3) . '/packages/player/dist/image-puzzle-player.bundle.js';
        if (!is_file($built)) {
            self::markTestSkipped('packages/player is not built (npm run build); the CI js job checks the copy.');
        }
        self::assertSame(hash_file('sha256', $built), hash_file('sha256', Puzzles::scriptPath()));
    }
}
