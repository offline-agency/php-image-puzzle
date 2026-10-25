<?php

declare(strict_types=1);

namespace ImagePuzzle\Filament\Http;

use Illuminate\Contracts\Auth\Access\Gate;
use ImagePuzzle\Filament\PuzzleModels;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves a record's saved source image to users who may "view" the record,
 * for the configurator preview. There is no route that serves arbitrary
 * paths: the file is whatever the record points at on its source disk.
 */
final class SourceImageController
{
    private const TYPES = [IMAGETYPE_PNG => 'image/png', IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_GIF => 'image/gif', IMAGETYPE_WEBP => 'image/webp'];

    public function __invoke(string $puzzle, PuzzleModels $models, Gate $gate): BinaryFileResponse
    {
        $record = $models->find($puzzle);
        if ($record === null) {
            throw new NotFoundHttpException('Unknown puzzle.');
        }
        if (!$gate->allows('view', $record)) {
            throw new AccessDeniedHttpException('This puzzle is not available to you.');
        }
        $path = $record->imagePuzzleSourcePath();
        $info = $path === null ? false : @getimagesize($path);
        if ($path === null || $info === false || !isset(self::TYPES[$info[2]])) {
            throw new NotFoundHttpException('No source image.');
        }

        return new BinaryFileResponse($path, 200, [
            'Content-Type' => self::TYPES[$info[2]],
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ], false);
    }
}
