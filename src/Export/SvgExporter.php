<?php

declare(strict_types=1);

namespace ImagePuzzle\Export;

use ImagePuzzle\Exception\ExportFailedException;
use ImagePuzzle\Exception\InvalidArgumentException;
use ImagePuzzle\Exception\ResourceLimitExceededException;
use ImagePuzzle\Game\PuzzleDirectory;
use ImagePuzzle\Support\ErrorCapture;

/**
 * Vector export of a built puzzle from the outlines in its definition: the
 * same paths the PHP rasterizer cut and the player hit-tests.
 *
 * Coordinates are world (source pixel) coordinates; the viewBox is the board
 * crop. Pieces are <use> references to one image clipped by their outline,
 * so the SVG reassembles the picture exactly where the raster pieces lie.
 *
 * The SVG is generated, never copied: it holds no scripts, event handlers,
 * external stylesheets or foreign content. The only link it can contain is
 * the source image, as the definition's relative path or a data URI. Text is
 * escaped. Remote (https) images can only be exported as outlines.
 */
final class SvgExporter
{
    public const DEFAULT_MAX_EMBED_BYTES = 32 * 1024 * 1024;

    private const MIME = [IMAGETYPE_PNG => 'image/png', IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_WEBP => 'image/webp', IMAGETYPE_GIF => 'image/gif'];

    public function __construct(
        private readonly int $maxEmbedBytes = self::DEFAULT_MAX_EMBED_BYTES,
    ) {
    }

    public function render(PuzzleDirectory $puzzle, SvgOptions $options = new SvgOptions()): string
    {
        $definition = $puzzle->definition;
        $crop = $definition->crop;
        $href = $this->href($puzzle, $options);
        $p = $options->precision;
        $size = $options->widthMm === null
            ? sprintf('width="%d" height="%d"', $crop->width, $crop->height)
            : sprintf('width="%smm" height="%smm"', self::num($options->widthMm, 3), self::num($options->widthMm * $crop->height / $crop->width, 3));

        $out = ['<?xml version="1.0" encoding="UTF-8"?>'];
        $out[] = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" version="1.1" viewBox="%d %d %d %d" %s data-definition="%s">',
            $crop->x,
            $crop->y,
            $crop->width,
            $crop->height,
            $size,
            self::escape($definition->id),
        );
        $out[] = '<title>' . self::escape(self::text($options->title ?? 'Image puzzle ' . $definition->kind->value . ' ' . $definition->rows . 'x' . $definition->cols)) . '</title>';
        if ($options->description !== null) {
            $out[] = '<desc>' . self::escape(self::text($options->description)) . '</desc>';
        }
        $outlines = $puzzle->outlines();
        $paths = [];
        foreach ($outlines as $id => $polygon) {
            $paths[$id] = self::path($polygon, $p);
        }

        if ($options->pieces && $href !== null) {
            $out[] = '<defs>';
            $out[] = sprintf(
                '<image id="source" x="0" y="0" width="%d" height="%d" preserveAspectRatio="none" href="%s" xlink:href="%s"/>',
                $definition->sourceWidth,
                $definition->sourceHeight,
                self::escape($href),
                self::escape($href),
            );
            foreach ($paths as $id => $d) {
                $out[] = sprintf('<clipPath id="clip-%s" clipPathUnits="userSpaceOnUse"><path d="%s"/></clipPath>', $id, $d);
            }
            $out[] = '</defs>';
            $out[] = '<g id="pieces">';
            foreach (array_keys($paths) as $id) {
                $out[] = sprintf('<use href="#source" xlink:href="#source" clip-path="url(#clip-%1$s)" data-piece="%1$s"/>', $id);
            }
            $out[] = '</g>';
        }
        if ($options->outlines) {
            $out[] = sprintf(
                '<g id="outlines" fill="none" stroke="%s" stroke-width="%s" stroke-linejoin="round" stroke-linecap="round">',
                strtolower($options->stroke),
                self::num($options->strokeWidth, 3),
            );
            foreach ($paths as $id => $d) {
                $out[] = sprintf('<path id="outline-%s" d="%s"/>', $id, $d);
            }
            $out[] = '</g>';
        }
        $out[] = '</svg>';

        return implode("\n", $out) . "\n";
    }

    /**
     * Writes the SVG to a new file ($target must not exist).
     */
    public function write(PuzzleDirectory $puzzle, string $target, SvgOptions $options = new SvgOptions()): void
    {
        $svg = $this->render($puzzle, $options);
        $handle = ErrorCapture::run(static fn () => fopen($target, 'xb'));
        if ($handle === false) {
            throw new ExportFailedException("Cannot create {$target} (it must not exist yet).");
        }
        try {
            if (fwrite($handle, $svg) !== strlen($svg)) {
                throw new ExportFailedException("Cannot write {$target}.");
            }
        } finally {
            fclose($handle);
        }
    }

    private function href(PuzzleDirectory $puzzle, SvgOptions $options): ?string
    {
        if (!$options->pieces || $options->image === SvgOptions::IMAGE_NONE) {
            return null;
        }
        if (!$puzzle->hasLocalImage()) {
            throw new InvalidArgumentException('The source image is remote; export outlines only (image "none").');
        }
        if ($options->image === SvgOptions::IMAGE_LINK) {
            return $puzzle->definition->image;
        }
        $file = $puzzle->file($puzzle->definition->image);
        $bytes = (int) filesize($file);
        if ($bytes > $this->maxEmbedBytes) {
            throw new ResourceLimitExceededException("The source image ({$bytes} bytes) is larger than the {$this->maxEmbedBytes}-byte embedding limit; link it instead.");
        }
        $data = ErrorCapture::run(static fn (): string|false => file_get_contents($file));
        $info = $data === false ? false : ErrorCapture::run(static fn (): array|false => getimagesizefromstring($data));
        if ($data === false || $info === false || !isset(self::MIME[$info[2]])) {
            throw new ExportFailedException("Cannot embed {$puzzle->definition->image}: not a PNG, JPEG, WebP or GIF image.");
        }

        return 'data:' . self::MIME[$info[2]] . ';base64,' . base64_encode($data);
    }

    /**
     * @param list<array{0: float, 1: float}> $polygon
     */
    private static function path(array $polygon, int $precision): string
    {
        $parts = [];
        foreach ($polygon as $i => [$x, $y]) {
            $parts[] = ($i === 0 ? 'M' : 'L') . self::num($x, $precision) . ' ' . self::num($y, $precision);
        }

        return implode(' ', $parts) . ' Z';
    }

    private static function num(float $value, int $precision): string
    {
        $formatted = rtrim(rtrim(number_format($value, $precision, '.', ''), '0'), '.');

        return $formatted === '-0' || $formatted === '' ? '0' : $formatted;
    }

    /** Removes characters XML 1.0 forbids and limits the length. */
    private static function text(string $value): string
    {
        $clean = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value);
        if ($clean === null) {
            throw new InvalidArgumentException('SVG text must be valid UTF-8.');
        }

        preg_match('/^.{0,' . SvgOptions::MAX_TEXT . '}/us', $clean, $match);

        return $match[0] ?? '';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
