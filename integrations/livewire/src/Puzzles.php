<?php

declare(strict_types=1);

namespace ImagePuzzle\Livewire;

use Illuminate\Contracts\Auth\Access\Gate;
use ImagePuzzle\Livewire\Contracts\PuzzleRepository;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The single place where a puzzle key from a request becomes a puzzle:
 * unknown keys are 404, denied ones 403, for the component and the routes.
 */
final readonly class Puzzles
{
    public function __construct(
        private PuzzleRepository $repository,
        private Gate $gate,
    ) {
    }

    public function authorized(string $key): StoredPuzzle
    {
        $puzzle = $this->repository->find($key);
        if ($puzzle === null) {
            throw new NotFoundHttpException('Unknown puzzle.');
        }
        if (!$this->gate->allows(ImagePuzzleServiceProvider::ABILITY, [$key, $puzzle->subject])) {
            throw new AccessDeniedHttpException('This puzzle is not available to you.');
        }

        return $puzzle;
    }

    /** The puzzle when it exists and the current user may play it; null otherwise (for hosts that render a fallback). */
    public function playable(string $key): ?StoredPuzzle
    {
        $puzzle = $this->repository->find($key);

        return $puzzle !== null && $this->gate->allows(ImagePuzzleServiceProvider::ABILITY, [$key, $puzzle->subject]) ? $puzzle : null;
    }

    /** The bundled player script and a content hash for cache busting. */
    public static function scriptPath(): string
    {
        return dirname(__DIR__) . '/resources/dist/image-puzzle-player.bundle.js';
    }

    public static function scriptVersion(): string
    {
        $hash = hash_file('sha256', self::scriptPath());

        return $hash === false ? '0' : substr($hash, 0, 16);
    }
}
