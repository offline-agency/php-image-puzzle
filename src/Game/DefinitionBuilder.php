<?php

declare(strict_types=1);

namespace ImagePuzzle\Game;

use ImagePuzzle\Config\PuzzleConfig;
use ImagePuzzle\Exception\ExportFailedException;
use ImagePuzzle\Exception\InvalidArgumentException;
use ImagePuzzle\ImageLoader;
use ImagePuzzle\PuzzleGenerator;
use ImagePuzzle\ResourceLimits;
use ImagePuzzle\Support\ErrorCapture;

/**
 * Builds a playable game definition and every asset it references into one
 * directory: the source image (normalised when EXIF orientation would make
 * browsers display it differently from its pixels), jigsaw pieces, and
 * definition.json. The directory must be new or empty, so a build never
 * overwrites another puzzle's assets; a failed build removes what it wrote.
 */
final class DefinitionBuilder
{
    public const DEFINITION_FILE = 'definition.json';

    /** Custom keys accepted for jigsaw builds (grid modes use GameFactory's keys). */
    public const JIGSAW_KEYS = ['rows', 'cols', 'topology', 'edge', 'snap'];

    private const EXTENSIONS = ['png' => 'png', 'jpeg' => 'jpg', 'gif' => 'gif', 'webp' => 'webp'];

    private readonly ResourceLimits $limits;

    public function __construct(
        private readonly GameFactory $factory = new GameFactory(),
        ?ResourceLimits $limits = null,
    ) {
        $this->limits = $limits ?? new ResourceLimits();
    }

    /**
     * @param array<string, mixed> $custom parameters for the "custom" preset (see docs/GAME_MODES.md)
     */
    public function build(string $sourcePath, string $outputDir, GameKind $kind, string $preset = 'medium', array $custom = [], int $seed = 0): BuildResult
    {
        if (!in_array($kind, Modes::available(), true)) {
            throw new InvalidArgumentException("The {$kind->value} mode cannot be built yet.");
        }
        $info = (new ImageLoader($this->limits))->inspect($sourcePath);
        $created = $this->prepare($outputDir);
        try {
            $image = $this->writeSource($sourcePath, $outputDir, $info->type, $info->orientation);
            $source = SourceImage::fromFile($outputDir . '/' . $image, new ImageLoader($this->limits));
            $definition = $kind === GameKind::Jigsaw
                ? $this->jigsaw($outputDir, $image, $source, $preset, $custom, $seed)
                : $this->factory->create($kind, $source, $image, $preset, $custom, $seed);
            $this->writeAtomically($outputDir . '/' . self::DEFINITION_FILE, $definition->toJson() . "\n");
        } catch (\Throwable $e) {
            self::clear($outputDir, $created);
            throw $e;
        }

        return new BuildResult($definition, $outputDir, self::files($outputDir));
    }

    /**
     * @param array<string, mixed> $custom
     */
    private function jigsaw(string $outputDir, string $image, SourceImage $source, string $preset, array $custom, int $seed): GameDefinition
    {
        if ($preset === 'custom') {
            $unknown = array_diff(array_keys($custom), self::JIGSAW_KEYS);
            if ($unknown !== []) {
                throw new InvalidArgumentException('Unknown custom jigsaw parameter(s): ' . implode(', ', $unknown) . '.');
            }
            $params = $custom;
        } else {
            if ($custom !== []) {
                throw new InvalidArgumentException('Custom parameters require the "custom" preset.');
            }
            $params = Presets::get(GameKind::Jigsaw, $preset);
        }
        $rows = Doc::positive($params, 'rows', 'jigsaw', 64);
        $cols = Doc::positive($params, 'cols', 'jigsaw', 64);
        $topology = isset($params['topology']) ? Doc::string($params, 'topology', 'jigsaw', 16) : 'rect';
        $edge = isset($params['edge']) ? Doc::string($params, 'edge', 'jigsaw', 32) : 'jigsaw';
        $snap = isset($params['snap']) ? Doc::positive($params, 'snap', 'jigsaw', 256) : null;
        $this->limits->assertPieceCount($rows, $cols);

        $config = PuzzleConfig::fromArray([
            'configVersion' => 2,
            'rows' => $rows,
            'cols' => $cols,
            'edge' => ['type' => $edge],
            'geometry' => ['seed' => $seed, 'topology' => $topology],
        ]);
        $result = PuzzleGenerator::generateAndExport(
            $outputDir . '/' . $image,
            $outputDir . '/pieces',
            $rows,
            $cols,
            $config->edgeStyle(),
            $config->exportOptions(),
            $this->limits,
            $config->geometryOptions(),
        );
        $manifest = json_decode((string) file_get_contents($result->getManifestPath()), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($manifest)) {
            throw new ExportFailedException('The exporter wrote an invalid manifest.');
        }
        $manifestArray = [];
        foreach ($manifest as $key => $value) {
            $manifestArray[(string) $key] = $value;
        }

        return $this->factory->jigsawFromManifest($manifestArray, $image, $preset, $seed, $source->sha256, 'pieces/', $snap);
    }

    /**
     * Copies the source, or re-encodes it upright as PNG when its EXIF
     * orientation is not the identity (browsers would rotate it on display).
     */
    private function writeSource(string $sourcePath, string $outputDir, string $type, ?int $orientation): string
    {
        if ($type === 'jpeg' && $orientation !== null && $orientation !== 1) {
            $gd = (new ImageLoader($this->limits, applyExifOrientation: true))->loadFromFile($sourcePath);
            $target = $outputDir . '/source.png';
            if (!ErrorCapture::run(static fn (): bool => imagepng($gd, $target, 6))) {
                throw new ExportFailedException("Cannot write {$target}.");
            }

            return 'source.png';
        }
        $name = 'source.' . self::EXTENSIONS[$type];
        if (!ErrorCapture::run(static fn (): bool => copy($sourcePath, $outputDir . '/' . $name))) {
            throw new ExportFailedException("Cannot copy the source image to {$outputDir}.");
        }

        return $name;
    }

    /** Returns true when the directory was created by this build. */
    private function prepare(string $outputDir): bool
    {
        if ($outputDir === '') {
            throw new InvalidArgumentException('The output directory cannot be empty.');
        }
        if (is_dir($outputDir)) {
            $entries = scandir($outputDir);
            if ($entries === false || array_diff($entries, ['.', '..']) !== []) {
                throw new InvalidArgumentException("The output directory must be empty: {$outputDir}");
            }

            return false;
        }
        if (file_exists($outputDir)) {
            throw new InvalidArgumentException("The output path is not a directory: {$outputDir}");
        }
        if (!ErrorCapture::run(static fn (): bool => mkdir($outputDir, 0755, true))) {
            throw new ExportFailedException("Cannot create {$outputDir}.");
        }

        return true;
    }

    private function writeAtomically(string $path, string $contents): void
    {
        $tmp = $path . '.tmp';
        if (file_put_contents($tmp, $contents) !== strlen($contents) || !rename($tmp, $path)) {
            @unlink($tmp);
            throw new ExportFailedException("Cannot write {$path}.");
        }
    }

    /** Removes what the build wrote: the directory itself when it created it, otherwise its contents. */
    private static function clear(string $dir, bool $removeDir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        if ($removeDir) {
            @rmdir($dir);
        }
    }

    /**
     * @return list<string> paths relative to $dir, sorted
     */
    private static function files(string $dir): array
    {
        $files = [];
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($items as $item) {
            if ($item instanceof \SplFileInfo && $item->isFile()) {
                $files[] = str_replace('\\', '/', substr($item->getPathname(), strlen($dir) + 1));
            }
        }
        sort($files);

        return $files;
    }
}
