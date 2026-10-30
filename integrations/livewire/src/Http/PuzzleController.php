<?php

declare(strict_types=1);

namespace ImagePuzzle\Livewire\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use ImagePuzzle\Livewire\Puzzles;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves the player script, and a puzzle's definition and the asset files
 * that definition references, after the same authorization as the component.
 */
final class PuzzleController
{
    private const TYPES = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'];

    public function __construct(private readonly Puzzles $puzzles)
    {
    }

    public function script(Request $request): BinaryFileResponse
    {
        $response = new BinaryFileResponse(Puzzles::scriptPath(), 200, ['Content-Type' => 'text/javascript; charset=utf-8'], true, null, true, true);
        if ($request->query('v') === Puzzles::scriptVersion()) {
            $response->setPublic()->setMaxAge(31536000)->setImmutable();
        } else {
            $response->setPublic()->setMaxAge(300);
        }

        return $response;
    }

    public function definition(Request $request, string $puzzle): Response
    {
        $stored = $this->puzzles->authorized($puzzle);
        $response = new Response($stored->definition->toJson(0), 200, ['Content-Type' => 'application/json']);
        $response->setEtag($stored->definition->id)->setPrivate()->setMaxAge(0);
        $response->isNotModified($request);

        return $response;
    }

    public function asset(Request $request, string $puzzle, string $path): BinaryFileResponse
    {
        $stored = $this->puzzles->authorized($puzzle);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        // Only files the definition references, inside the puzzle directory.
        if (!in_array($path, $stored->assetPaths(), true) || !isset(self::TYPES[$extension])) {
            throw new NotFoundHttpException();
        }
        $base = realpath($stored->directory);
        $file = realpath($stored->directory . '/' . $path);
        if ($base === false || $file === false || !str_starts_with($file, $base . DIRECTORY_SEPARATOR) || !is_file($file)) {
            throw new NotFoundHttpException();
        }
        $response = new BinaryFileResponse($file, 200, ['Content-Type' => self::TYPES[$extension]], false, null, true, true);
        $response->setPrivate()->setMaxAge(3600);
        $response->isNotModified($request);

        return $response;
    }
}
