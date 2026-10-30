<?php

declare(strict_types=1);

namespace Config;

use App\Libraries\PuzzleService;
use CodeIgniter\Config\BaseService;
use ImagePuzzle\Game\DefinitionBuilder;
use ImagePuzzle\ResourceLimits;

/**
 * Services configuration file. The image-puzzle services are injected into
 * controllers and tests through these factories.
 */
class Services extends BaseService
{
    public static function puzzleBuilder(bool $getShared = true): DefinitionBuilder
    {
        if ($getShared) {
            return static::getSharedInstance('puzzleBuilder');
        }
        $config = config(ImagePuzzle::class);

        return new DefinitionBuilder(limits: new ResourceLimits(
            maxWidth: $config->maxWidth,
            maxHeight: $config->maxHeight,
            maxPixels: $config->maxPixels,
        ));
    }

    public static function puzzles(bool $getShared = true): PuzzleService
    {
        if ($getShared) {
            return static::getSharedInstance('puzzles');
        }

        return new PuzzleService(static::puzzleBuilder(), config(ImagePuzzle::class));
    }
}
