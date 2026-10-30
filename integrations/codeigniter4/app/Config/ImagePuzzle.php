<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Where puzzles live and how large uploads may be. Every directory is chosen
 * here, never by a request.
 */
class ImagePuzzle extends BaseConfig
{
    /** Uploaded originals, outside the web root. */
    public string $uploadDirectory = WRITEPATH . 'uploads/puzzles';

    /** Built puzzles, served as static files under /puzzles/<id>/. */
    public string $publicDirectory = FCPATH . 'puzzles';

    /** Validated saved games, one JSON file per puzzle. */
    public string $progressDirectory = WRITEPATH . 'puzzle-progress';

    /** Upload size limit in kilobytes (CodeIgniter's max_size rule). */
    public int $maxUploadKb = 20480;

    /** Decoding limits passed to ImagePuzzle\ResourceLimits. */
    public int $maxWidth = 6000;

    public int $maxHeight = 6000;

    public int $maxPixels = 24_000_000;

    /**
     * Modes an author may pick in the form.
     *
     * @var list<string>
     */
    public array $kinds = ['jigsaw', 'sliding', 'swap', 'rotation', 'strips', 'memory', 'tangram', 'nonogram'];
}
