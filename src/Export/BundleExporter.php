<?php

declare(strict_types=1);

namespace ImagePuzzle\Export;

use Composer\InstalledVersions;
use ImagePuzzle\Exception\CapabilityUnavailableException;
use ImagePuzzle\Exception\ExportFailedException;
use ImagePuzzle\Exception\InvalidArgumentException;
use ImagePuzzle\Exception\ResourceLimitExceededException;
use ImagePuzzle\Game\PuzzleDirectory;
use ImagePuzzle\ImageLoader;
use ImagePuzzle\ResourceLimits;
use ImagePuzzle\Support\ErrorCapture;
use ZipArchive;

/**
 * Self-contained ZIP of a built puzzle, playable offline:
 *
 *     <root>/index.html                     player page, definition inline
 *     <root>/player/image-puzzle-player.iife.js
 *     <root>/definition.json                the definition, byte for byte
 *     <root>/<files the definition references>
 *     <root>/preview.png                    the solved board
 *     <root>/README.txt, NOTICE.txt, LICENSE.txt
 *     <root>/bundle.json                    every other entry with its size and SHA-256
 *
 * The page uses a classic script and an inline definition, so it also runs
 * from file:// (where browsers refuse to fetch JSON or load modules); no CDN
 * or network access is needed. Entries are sorted, carry a fixed date and
 * 0644 permissions, so the same puzzle always produces the same archive.
 * Needs ext-zip.
 */
final class BundleExporter
{
    public const FORMAT = 'image-puzzle-bundle';
    public const VERSION = 1;
    public const MANIFEST = 'bundle.json';
    public const PLAYER = 'player/image-puzzle-player.iife.js';
    public const DEFAULT_MAX_BYTES = 512 * 1024 * 1024;

    private const ROOT_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/';
    private const PREVIEW_WIDTH = 640;
    // 1980-01-02, the earliest date every ZIP reader accepts in local time.
    private const MTIME = 315_619_200;

    private readonly string $playerFile;

    public function __construct(
        private readonly int $maxBytes = self::DEFAULT_MAX_BYTES,
        ?string $playerFile = null,
        private readonly ?bool $zipAvailable = null,
        private readonly ResourceLimits $limits = new ResourceLimits(),
    ) {
        $this->playerFile = $playerFile ?? dirname(__DIR__, 2) . '/resources/dist/image-puzzle-player.iife.js';
    }

    public static function supported(): bool
    {
        return class_exists(ZipArchive::class);
    }

    /**
     * Archive entries (relative to the root folder) and their contents, in
     * archive order, without writing anything. bundle.json comes last.
     *
     * @return array<string, string>
     */
    public function entries(PuzzleDirectory $puzzle, ?string $title = null): array
    {
        if (!$puzzle->hasLocalImage()) {
            throw new InvalidArgumentException('The source image is remote; a bundle needs the image file.');
        }
        $definition = $puzzle->definition;
        $files = [];
        $total = 0;
        $add = function (string $path, string $bytes) use (&$files, &$total): void {
            if (isset($files[$path])) {
                throw new ExportFailedException("Duplicate bundle entry {$path}.");
            }
            $total += strlen($bytes);
            if ($total > $this->maxBytes) {
                throw new ResourceLimitExceededException("The bundle exceeds the {$this->maxBytes}-byte limit.");
            }
            $files[$path] = $bytes;
        };

        $add('definition.json', self::read($puzzle->file('definition.json')));
        foreach ($puzzle->referencedFiles() as $relative) {
            $absolute = $puzzle->file($relative);
            if ($total + (int) filesize($absolute) > $this->maxBytes) {
                throw new ResourceLimitExceededException("The bundle exceeds the {$this->maxBytes}-byte limit.");
            }
            $add($relative, self::read($absolute));
        }
        $add('preview.png', $this->preview($puzzle));
        if (!is_file($this->playerFile)) {
            throw new ExportFailedException("The player script is missing ({$this->playerFile}); reinstall the package.");
        }
        $add(self::PLAYER, self::read($this->playerFile));
        $add('index.html', self::page($files['definition.json'], $title ?? 'Image puzzle'));
        $add('README.txt', self::readme());
        $add('NOTICE.txt', self::notice($definition->id, $definition->sourceSha256));
        $license = dirname(__DIR__, 2) . '/LICENSE';
        if (is_file($license)) {
            $add('LICENSE.txt', self::read($license));
        }
        ksort($files, SORT_STRING);

        $manifest = ['format' => self::FORMAT, 'version' => self::VERSION, 'definition' => $definition->id, 'entry' => 'index.html', 'files' => []];
        foreach ($files as $path => $bytes) {
            $manifest['files'][] = ['path' => $path, 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
        }
        $files[self::MANIFEST] = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";

        return $files;
    }

    /**
     * Writes the archive to $target, which must not exist. Entries are
     * placed under the folder $root.
     *
     * @return list<string> archive entry names
     */
    public function write(PuzzleDirectory $puzzle, string $target, string $root = 'image-puzzle', ?string $title = null): array
    {
        if (!($this->zipAvailable ?? self::supported())) {
            throw new CapabilityUnavailableException('ZIP bundles need the zip extension (ext-zip).');
        }
        if (preg_match(self::ROOT_PATTERN, $root) !== 1 || $root === '..') {
            throw new InvalidArgumentException('The bundle folder name must be 1-64 letters, digits, dots, dashes or underscores.');
        }
        if (file_exists($target)) {
            throw new ExportFailedException("{$target} already exists.");
        }
        $entries = $this->entries($puzzle, $title);
        $partial = $target . '.part-' . bin2hex(random_bytes(4));
        $zip = new ZipArchive();
        if ($zip->open($partial, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new ExportFailedException("Cannot create {$target}.");
        }
        $names = [];
        try {
            foreach ($entries as $path => $bytes) {
                $name = "{$root}/{$path}";
                if (!$zip->addFromString($name, $bytes, ZipArchive::FL_OVERWRITE)) {
                    throw new ExportFailedException("Cannot add {$name} to the bundle.");
                }
                $zip->setMtimeName($name, self::MTIME);
                $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, 0o100644 << 16);
                $zip->setCompressionName($name, str_ends_with($path, '.png') || str_ends_with($path, '.webp') || str_ends_with($path, '.jpg') ? ZipArchive::CM_STORE : ZipArchive::CM_DEFLATE);
                $names[] = $name;
            }
            if (!$zip->close()) {
                throw new ExportFailedException("Cannot write {$target}.");
            }
            if (file_exists($target) || !ErrorCapture::run(static fn (): bool => rename($partial, $target))) {
                throw new ExportFailedException("Cannot move the bundle to {$target}.");
            }
        } finally {
            if (is_file($partial)) {
                unlink($partial);
            }
        }

        return $names;
    }

    private function preview(PuzzleDirectory $puzzle): string
    {
        $definition = $puzzle->definition;
        $crop = $definition->crop;
        $source = (new ImageLoader($this->limits))->loadFromFile($puzzle->file($definition->image));
        $width = max(1, min(self::PREVIEW_WIDTH, $crop->width));
        $height = max(1, (int) round($width * $crop->height / $crop->width));
        $canvas = imagecreatetruecolor($width, $height);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagecopyresampled($canvas, $source, 0, 0, $crop->x, $crop->y, $width, $height, $crop->width, $crop->height);
        $stream = fopen('php://memory', 'w+b');
        if ($stream === false || !imagepng($canvas, $stream, 9)) {
            throw new ExportFailedException('Cannot encode the bundle preview.');
        }
        rewind($stream);
        $bytes = (string) stream_get_contents($stream);
        fclose($stream);

        return $bytes;
    }

    private static function read(string $file): string
    {
        $bytes = ErrorCapture::run(static fn (): string|false => file_get_contents($file));
        if ($bytes === false) {
            throw new ExportFailedException("Cannot read {$file}.");
        }

        return $bytes;
    }

    private static function page(string $definitionJson, string $title): string
    {
        // Re-encoded so "</script>" or "<!--" in any string cannot end the element.
        $json = json_encode(json_decode($definitionJson, false, 512, JSON_THROW_ON_ERROR), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR);
        $title = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        $player = self::PLAYER;

        return <<<HTML
            <!doctype html>
            <html lang="en">
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>{$title}</title>
            <style>
              body { margin: 0; padding: 16px; font-family: system-ui, sans-serif; background: #f5f5f4; color: #1c1917; }
              main { max-width: 960px; margin: 0 auto; }
              h1 { font-size: 1.25rem; margin: 0 0 12px; }
              image-puzzle-player { display: block; }
            </style>
            </head>
            <body>
            <main>
              <h1>{$title}</h1>
              <image-puzzle-player></image-puzzle-player>
              <noscript><p>This puzzle needs JavaScript. <img src="preview.png" alt="Solved puzzle" width="100%"></p></noscript>
            </main>
            <script type="application/json" id="puzzle-definition">{$json}</script>
            <script src="{$player}"></script>
            <script>
              document.querySelector('image-puzzle-player').definition = JSON.parse(document.getElementById('puzzle-definition').textContent);
            </script>
            </body>
            </html>

            HTML;
    }

    private static function readme(): string
    {
        return <<<'TEXT'
            Image puzzle bundle
            ===================

            Open index.html in a browser to play. Everything the page needs is in
            this folder: no network access or CDN is used.

            - Extract the whole archive first; the page loads files next to it.
            - Opening index.html directly (file://) works because the definition is
              inline in the page and the player is a classic script.
            - To serve it instead, run a static server in this folder, for example
              "php -S 127.0.0.1:8000" or "python3 -m http.server", and open
              http://127.0.0.1:8000/.
            - definition.json, the piece images and preview.png can be reused with
              the player in another page; bundle.json lists every file with its
              SHA-256.

            TEXT;
    }

    private static function notice(string $definitionId, ?string $sourceSha256): string
    {
        $version = class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('offline-agency/image-puzzle')
            ? (string) InstalledVersions::getPrettyVersion('offline-agency/image-puzzle')
            : 'unknown';
        $source = $sourceSha256 ?? 'not recorded';

        return <<<TEXT
            Generated with offline-agency/image-puzzle {$version}
            https://github.com/offline-agency/php-image-puzzle

            Definition: {$definitionId}
            Source image SHA-256: {$source}

            The player script (player/) is part of offline-agency/image-puzzle and
            is distributed under the MIT License in LICENSE.txt.

            The picture and the piece images are derived from the source image.
            They are not covered by that license: their rights belong to the
            owner of the source image.

            TEXT;
    }
}
