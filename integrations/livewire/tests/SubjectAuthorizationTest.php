<?php

declare(strict_types=1);

namespace ImagePuzzle\Livewire\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Access\Gate;
use ImagePuzzle\Livewire\Contracts\PuzzleRepository;
use ImagePuzzle\Livewire\PuzzlePlayer;
use ImagePuzzle\Livewire\StoredPuzzle;
use Livewire\Livewire;

final class OwnedPuzzle
{
    public function __construct(public int $ownerId)
    {
    }
}

final class SubjectAuthorizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $built = self::puzzle('swap');
        $this->app?->instance(PuzzleRepository::class, new class ($built->definition, $built->directory) implements PuzzleRepository {
            public function __construct(private \ImagePuzzle\Game\GameDefinition $definition, private string $directory)
            {
            }

            public function find(string $key): ?StoredPuzzle
            {
                return match ($key) {
                    'mine' => new StoredPuzzle($key, $this->definition, $this->directory, new OwnedPuzzle(1)),
                    'theirs' => new StoredPuzzle($key, $this->definition, $this->directory, new OwnedPuzzle(2)),
                    default => null,
                };
            }
        });
        $this->app?->make(Gate::class)->define('view', static fn (?GenericUser $user, OwnedPuzzle $puzzle): bool => $user !== null && $user->getAuthIdentifier() === $puzzle->ownerId);
    }

    private function user(int $id): GenericUser
    {
        return new GenericUser(['id' => $id]);
    }

    public function test_record_puzzles_need_the_view_ability_on_the_record(): void
    {
        $this->actingAs($this->user(1));
        Livewire::test(PuzzlePlayer::class, ['puzzle' => 'mine'])->assertOk();
        Livewire::test(PuzzlePlayer::class, ['puzzle' => 'theirs'])->assertStatus(403);
        $this->get('/image-puzzle/puzzles/mine/definition.json')->assertOk();
        $this->get('/image-puzzle/puzzles/theirs/definition.json')->assertForbidden();
        $this->get('/image-puzzle/puzzles/theirs/source.png')->assertForbidden();
    }

    public function test_guests_are_denied_record_puzzles_by_default(): void
    {
        Livewire::test(PuzzlePlayer::class, ['puzzle' => 'mine'])->assertStatus(403);
        $this->get('/image-puzzle/puzzles/mine/definition.json')->assertForbidden();
    }
}
