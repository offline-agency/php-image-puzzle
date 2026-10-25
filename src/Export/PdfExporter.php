<?php

declare(strict_types=1);

namespace ImagePuzzle\Export;

use GdImage;
use ImagePuzzle\Exception\ExportFailedException;
use ImagePuzzle\Exception\InvalidArgumentException;
use ImagePuzzle\ExportOptions;
use ImagePuzzle\Game\PuzzleDirectory;
use ImagePuzzle\ImageLoader;
use ImagePuzzle\ResourceLimits;
use ImagePuzzle\Support\ErrorCapture;

/**
 * Printable PDF of a built puzzle: the board at an exact physical size with
 * its cut lines, tiled over A4/A3 sheets when it is larger than one, plus an
 * optional reference page of the solved board with piece numbers.
 *
 * Self-contained PDF 1.4 written without extensions: the picture is one
 * JPEG image (DCTDecode) shared by every page, cut lines are vector paths
 * from the definition outlines (the geometry of the raster pieces), text
 * uses the standard Helvetica font. Content streams are Flate-compressed
 * when zlib is available. No dates are written, so the same input always
 * produces the same file.
 */
final class PdfExporter
{
    private const PT_PER_MM = 72 / 25.4;

    public function __construct(
        private readonly ResourceLimits $limits = new ResourceLimits(),
    ) {
    }

    /**
     * @return array{pages: int, sheets: array{0: int, 1: int}, paper: array{0: float, 1: float}, board: array{0: float, 1: float}} layout in mm
     */
    public function layout(PuzzleDirectory $puzzle, PdfOptions $options = new PdfOptions()): array
    {
        $plan = $this->plan($puzzle, $options);

        return [
            'pages' => $plan['cols'] * $plan['rows'] + ($options->reference ? 1 : 0),
            'sheets' => [$plan['cols'], $plan['rows']],
            'paper' => [$plan['paperW'], $plan['paperH']],
            'board' => [$plan['boardW'], $plan['boardH']],
        ];
    }

    public function render(PuzzleDirectory $puzzle, PdfOptions $options = new PdfOptions()): string
    {
        if (!$puzzle->hasLocalImage()) {
            throw new InvalidArgumentException('The source image is remote; a PDF needs the image file.');
        }
        // The picture is embedded as JPEG.
        ImageEncoder::assertSupported(ExportOptions::FORMAT_JPEG);
        $definition = $puzzle->definition;
        $plan = $this->plan($puzzle, $options);
        $crop = $definition->crop;
        $jpeg = $this->image($puzzle, $plan['imageWidth'], $plan['imageHeight'], $options->jpegQuality);
        $outlines = $puzzle->outlines();
        $pt = self::PT_PER_MM;
        [$paperW, $paperH] = [$plan['paperW'] * $pt, $plan['paperH'] * $pt];
        $margin = $options->marginMm * $pt;
        [$printW, $printH] = [$paperW - 2 * $margin, $paperH - 2 * $margin];
        $boardW = $plan['boardW'] * $pt;
        $boardH = $plan['boardH'] * $pt;
        $color = self::rgb($options->lineColor);

        $pages = [];
        for ($row = 0; $row < $plan['rows']; $row++) {
            for ($col = 0; $col < $plan['cols']; $col++) {
                // Board top-left on this sheet (PDF y grows upwards).
                $x0 = $margin - $col * $printW;
                $top = $paperH - $margin + $row * $printH;
                $scale = $boardW / $crop->width;
                $ops = ['q', sprintf('%s %s %s %s re W n', self::n($margin), self::n($margin), self::n($printW), self::n($printH))];
                $ops[] = sprintf('q %s 0 0 %s %s %s cm /Im1 Do Q', self::n($boardW), self::n($boardH), self::n($x0), self::n($top - $boardH));
                if ($options->cutLines) {
                    $ops[] = sprintf('%s w 1 J 1 j %s RG', self::n($options->lineWidthPt), $color);
                    foreach ($outlines as $polygon) {
                        $ops[] = self::polygon($polygon, $x0, $top, $scale, $crop->x, $crop->y) . ' S';
                    }
                }
                $ops[] = 'Q';
                $ops = [...$ops, ...self::cropMarks($margin, $paperW, $paperH)];
                $label = sprintf(
                    'Sheet %d of %d (row %d, column %d) - board %s x %s mm at 100%% - print without scaling',
                    $row * $plan['cols'] + $col + 1,
                    $plan['rows'] * $plan['cols'],
                    $row + 1,
                    $col + 1,
                    self::n($plan['boardW'], 1),
                    self::n($plan['boardH'], 1),
                );
                $ops[] = self::text($label, $margin + 6, $margin / 2 - 2.5, 7);
                $pages[] = implode("\n", $ops);
            }
        }
        if ($options->reference) {
            $fit = min($printW / $boardW, ($printH - 14) / $boardH, 1.0);
            [$w, $h] = [$boardW * $fit, $boardH * $fit];
            $x0 = ($paperW - $w) / 2;
            $top = $paperH - $margin - 14;
            $scale = $w / $crop->width;
            $ops = [self::text(self::ascii($options->title ?? 'Reference: solved board') . ' - ' . count($outlines) . ' pieces', $margin, $paperH - $margin - 9, 10)];
            $ops[] = sprintf('q %s 0 0 %s %s %s cm /Im1 Do Q', self::n($w), self::n($h), self::n($x0), self::n($top - $h));
            $ops[] = '0.35 w 1 J 1 j 1 1 1 RG';
            foreach ($outlines as $polygon) {
                $ops[] = self::polygon($polygon, $x0, $top, $scale, $crop->x, $crop->y) . ' S';
            }
            foreach ($definition->tiles as $tile) {
                $cx = $x0 + ($tile->rect->x + $tile->rect->width / 2 - $crop->x) * $scale;
                $cy = $top - ($tile->rect->y + $tile->rect->height / 2 - $crop->y) * $scale;
                $size = max(4.0, min(12.0, min($tile->rect->width, $tile->rect->height) * $scale / 3));
                $label = (string) ($tile->home + 1);
                $half = strlen($label) * $size * 0.28 + 1.5;
                $ops[] = sprintf('1 1 1 rg %s %s %s %s re f', self::n($cx - $half), self::n($cy - $size * 0.45), self::n(2 * $half), self::n($size * 1.1));
                $ops[] = '0 0 0 rg ' . self::text($label, $cx - $half + 1.5, $cy - $size * 0.3, $size);
            }
            $pages[] = implode("\n", $ops);
        }

        return self::document($pages, $paperW, $paperH, $jpeg, $plan['imageWidth'], $plan['imageHeight'], $options->title);
    }

    /**
     * Writes the PDF to a new file ($target must not exist).
     */
    public function write(PuzzleDirectory $puzzle, string $target, PdfOptions $options = new PdfOptions()): void
    {
        $pdf = $this->render($puzzle, $options);
        $handle = ErrorCapture::run(static fn () => fopen($target, 'xb'));
        if ($handle === false) {
            throw new ExportFailedException("Cannot create {$target} (it must not exist yet).");
        }
        try {
            if (fwrite($handle, $pdf) !== strlen($pdf)) {
                throw new ExportFailedException("Cannot write {$target}.");
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return array{paperW: float, paperH: float, boardW: float, boardH: float, cols: int, rows: int, imageWidth: int<1, max>, imageHeight: int<1, max>}
     */
    private function plan(PuzzleDirectory $puzzle, PdfOptions $options): array
    {
        $crop = $puzzle->definition->crop;
        $aspect = $crop->height / $crop->width;
        $candidates = [];
        foreach ($options->orientation === 'auto' ? ['portrait', 'landscape'] : [$options->orientation] as $orientation) {
            [$paperW, $paperH] = $options->paperSize($orientation);
            $printW = $paperW - 2 * $options->marginMm;
            $printH = $paperH - 2 * $options->marginMm;
            $boardW = $options->widthMm ?? min($printW, $printH / $aspect);
            $boardH = $boardW * $aspect;
            // Rounding guard: a board that fits exactly must not spill onto a second sheet.
            $cols = max(1, (int) ceil($boardW / $printW - 1e-9));
            $rows = max(1, (int) ceil($boardH / $printH - 1e-9));
            $candidates[] = ['paperW' => $paperW, 'paperH' => $paperH, 'boardW' => $boardW, 'boardH' => $boardH, 'cols' => $cols, 'rows' => $rows];
        }
        usort($candidates, static fn (array $a, array $b): int => [$a['cols'] * $a['rows'], -$a['boardW']] <=> [$b['cols'] * $b['rows'], -$b['boardW']]);
        $best = $candidates[0];
        $dpiWidth = (int) ceil($best['boardW'] / 25.4 * $options->maxDpi);
        $imageWidth = max(1, min($crop->width, $dpiWidth));
        $imageHeight = max(1, (int) round($imageWidth * $aspect));

        return $best + ['imageWidth' => $imageWidth, 'imageHeight' => $imageHeight];
    }

    /**
     * The board crop as a JPEG, flattened on white and downsampled to the DPI limit.
     *
     * @param int<1, max> $width
     * @param int<1, max> $height
     */
    private function image(PuzzleDirectory $puzzle, int $width, int $height, int $quality): string
    {
        $definition = $puzzle->definition;
        $source = (new ImageLoader($this->limits))->loadFromFile($puzzle->file($definition->image));
        $crop = $definition->crop;
        $canvas = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        if ($white === false) {
            throw new ExportFailedException('Cannot allocate white.');
        }
        imagefilledrectangle($canvas, 0, 0, $width - 1, $height - 1, $white);
        imagealphablending($canvas, true);
        imagecopyresampled($canvas, $source, 0, 0, $crop->x, $crop->y, $width, $height, $crop->width, $crop->height);

        return self::jpeg($canvas, $quality);
    }

    private static function jpeg(GdImage $image, int $quality): string
    {
        $stream = fopen('php://memory', 'w+b');
        if ($stream === false || !imagejpeg($image, $stream, $quality)) {
            throw new ExportFailedException('Cannot encode the PDF image as JPEG.');
        }
        rewind($stream);
        $bytes = (string) stream_get_contents($stream);
        fclose($stream);

        return $bytes;
    }

    /**
     * @param list<array{0: float, 1: float}> $polygon
     */
    private static function polygon(array $polygon, float $x0, float $top, float $scale, int $cropX, int $cropY): string
    {
        $parts = [];
        foreach ($polygon as $i => [$x, $y]) {
            $parts[] = self::n($x0 + ($x - $cropX) * $scale) . ' ' . self::n($top - ($y - $cropY) * $scale) . ($i === 0 ? ' m' : ' l');
        }

        return implode(' ', $parts) . ' h';
    }

    /**
     * Corner marks in the margin, aligned with the printable area, for
     * trimming and for joining tiled sheets.
     *
     * @return list<string>
     */
    private static function cropMarks(float $margin, float $paperW, float $paperH): array
    {
        $len = min($margin - 2, 5 * self::PT_PER_MM);
        $ops = ['q 0.25 w 0 0 0 RG'];
        foreach ([[$margin, $margin], [$paperW - $margin, $margin], [$margin, $paperH - $margin], [$paperW - $margin, $paperH - $margin]] as [$x, $y]) {
            $dx = $x < $paperW / 2 ? -1 : 1;
            $dy = $y < $paperH / 2 ? -1 : 1;
            $ops[] = sprintf('%s %s m %s %s l S', self::n($x + $dx * 2), self::n($y), self::n($x + $dx * (2 + $len)), self::n($y));
            $ops[] = sprintf('%s %s m %s %s l S', self::n($x), self::n($y + $dy * 2), self::n($x), self::n($y + $dy * (2 + $len)));
        }
        $ops[] = 'Q';

        return $ops;
    }

    private static function text(string $text, float $x, float $y, float $size): string
    {
        return sprintf('BT /F1 %s Tf %s %s Td (%s) Tj ET', self::n($size), self::n($x), self::n($y), self::escape(self::ascii($text)));
    }

    /**
     * Printable ASCII only: the standard font is used without embedding.
     * Each other character (or invalid byte) becomes "?".
     */
    private static function ascii(string $text): string
    {
        $clean = preg_replace('/[^\x20-\x7E]/u', '?', $text) ?? (string) preg_replace('/[^\x20-\x7E]/', '?', $text);

        return substr($clean, 0, 200);
    }

    private static function escape(string $text): string
    {
        return strtr($text, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
    }

    private static function rgb(string $hex): string
    {
        return sprintf('%s %s %s', self::n(hexdec(substr($hex, 1, 2)) / 255, 3), self::n(hexdec(substr($hex, 3, 2)) / 255, 3), self::n(hexdec(substr($hex, 5, 2)) / 255, 3));
    }

    private static function n(float $value, int $decimals = 2): string
    {
        $formatted = rtrim(rtrim(number_format($value, $decimals, '.', ''), '0'), '.');

        return $formatted === '-0' || $formatted === '' ? '0' : $formatted;
    }

    /**
     * @param list<string> $pages content streams
     */
    private static function document(array $pages, float $width, float $height, string $jpeg, int $imageWidth, int $imageHeight, ?string $title): string
    {
        $compress = function_exists('gzcompress');
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = [];
        $next = 6;
        foreach ($pages as $content) {
            $kids[] = "{$next} 0 R";
            $objects[$next] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %s %s] /Resources << /Font << /F1 3 0 R >> /XObject << /Im1 4 0 R >> >> /Contents %d 0 R >>', self::n($width), self::n($height), $next + 1);
            $body = $compress ? (string) gzcompress($content, 6) : $content;
            $objects[$next + 1] = sprintf("<< /Length %d%s >>\nstream\n%s\nendstream", strlen($body), $compress ? ' /Filter /FlateDecode' : '', $body);
            $next += 2;
        }
        $objects[2] = sprintf('<< /Type /Pages /Kids [%s] /Count %d >>', implode(' ', $kids), count($kids));
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = sprintf("<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length %d >>\nstream\n%s\nendstream", $imageWidth, $imageHeight, strlen($jpeg), $jpeg);
        $objects[5] = sprintf('<< /Producer (offline-agency/image-puzzle) /Title (%s) >>', self::escape(self::ascii($title ?? 'Image puzzle')));
        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $number => $object) {
            $offsets[$number] = strlen($pdf);
            $pdf .= "{$number} 0 obj\n{$object}\nendobj\n";
        }
        $xref = strlen($pdf);
        $count = count($objects) + 1;
        $pdf .= "xref\n0 {$count}\n0000000000 65535 f \n";
        for ($i = 1; $i < $count; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size {$count} /Root 1 0 R /Info 5 0 R >>\nstartxref\n{$xref}\n%%EOF\n";

        return $pdf;
    }
}
