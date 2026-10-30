<?php

declare(strict_types=1);

namespace ImagePuzzle\Game\Nonogram;

use GdImage;
use ImagePuzzle\Exception\InvalidArgumentException;
use ImagePuzzle\Game\Doc;
use ImagePuzzle\Geometry\Bounds;

/**
 * Turns an image into a monochrome nonogram grid.
 *
 * 1. Crop: the largest centred region whose aspect is cols:rows, so cells
 *    are square; the crop is recorded in the definition.
 * 2. Resample: each cell's luma (Rec. 709 weights, transparent pixels over
 *    white) is the mean of an evenly spaced sample grid of up to 8x8 pixel
 *    centres inside the cell. Pure PHP arithmetic, so every GD build gives
 *    the same grid.
 * 3. Threshold: cells darker than the threshold are filled ("invert" fills
 *    the light cells instead). "auto" uses Otsu's method on the cell lumas.
 * 4. Uniqueness: the clues are classified by NonogramSolver. When they are
 *    not proven unique, the threshold is nudged by ±10, ±20 and ±30 (seven
 *    attempts in total; a grid already classified is skipped) and the first
 *    unique grid wins; otherwise the first attempt with filled cells is kept
 *    with its honest label, or generation fails when uniqueness is required.
 *    Worst case (every distinct grid exhausts the solver budget): seven
 *    budgets, about 3 s for a 30x30 noise image on PHP 8.5 / arm64.
 */
final class NonogramGenerator
{
    public const MIN_SIDE = 2;
    public const MAX_SIDE = 30;
    public const SAMPLES = 8;
    public const OFFSETS = [0, 10, -10, 20, -20, 30, -30];

    public function __construct(
        private readonly int $budget = NonogramSolver::DEFAULT_BUDGET,
    ) {
    }

    /**
     * Largest centred crop of square cells for a rows x cols grid.
     */
    public static function crop(int $width, int $height, int $rows, int $cols): Bounds
    {
        $cell = min(intdiv($width, $cols), intdiv($height, $rows));
        if ($cell < 1) {
            throw new InvalidArgumentException("A {$width}x{$height} image cannot hold a {$rows}x{$cols} nonogram.");
        }

        return new Bounds(intdiv($width - $cell * $cols, 2), intdiv($height - $cell * $rows, 2), Doc::positiveValue($cell * $cols, 'crop width'), Doc::positiveValue($cell * $rows, 'crop height'));
    }

    /**
     * @return array{solution: string, threshold: int, uniqueness: string, attempts: int}
     *                                                                                    solution: row-major "1"/"0"; threshold: the one used
     */
    public function generate(GdImage $image, Bounds $crop, int $rows, int $cols, int|string $threshold = 'auto', bool $invert = false, bool $requireUnique = false): array
    {
        if ($rows < self::MIN_SIDE || $cols < self::MIN_SIDE || $rows > self::MAX_SIDE || $cols > self::MAX_SIDE) {
            throw new InvalidArgumentException('A nonogram must be between ' . self::MIN_SIDE . 'x' . self::MIN_SIDE . ' and ' . self::MAX_SIDE . 'x' . self::MAX_SIDE . " cells, got {$rows}x{$cols}.");
        }
        if (is_string($threshold) && $threshold !== 'auto') {
            throw new InvalidArgumentException('The nonogram threshold must be "auto" or 1-255.');
        }
        if (is_int($threshold) && ($threshold < 1 || $threshold > 255)) {
            throw new InvalidArgumentException('The nonogram threshold must be "auto" or 1-255.');
        }
        $lumas = self::lumas($image, $crop, $rows, $cols);
        $base = is_int($threshold) ? $threshold : self::otsu($lumas);
        $fallback = null;
        $attempts = 0;
        $tried = [];
        foreach (self::OFFSETS as $offset) {
            $level = max(1, min(255, $base + $offset));
            $attempts++;
            $solution = '';
            foreach ($lumas as $luma) {
                $solution .= (($luma < $level) !== $invert) ? '1' : '0';
            }
            if (!str_contains($solution, '1') || isset($tried[$solution])) {
                continue;
            }
            // A threshold that gives an already classified grid is not solved again.
            $tried[$solution] = true;
            $status = (new NonogramSolver(...self::cluesOf($solution, $rows, $cols), budget: $this->budget))->classify()['status'];
            $result = ['solution' => $solution, 'threshold' => $level, 'uniqueness' => $status, 'attempts' => $attempts];
            if ($status === NonogramSolver::UNIQUE) {
                return $result;
            }
            $fallback ??= $result;
        }
        if ($fallback === null) {
            throw new InvalidArgumentException('The image gives no filled cells at any tried threshold; use another image, threshold or "invert".');
        }
        if ($requireUnique) {
            throw new InvalidArgumentException("No tried threshold gives a nonogram with a proven unique solution (best: {$fallback['uniqueness']}); try another size or image.");
        }

        return $fallback;
    }

    /**
     * Row and column clues of a row-major solution.
     *
     * @return array{0: list<list<int>>, 1: list<list<int>>}
     */
    public static function cluesOf(string $solution, int $rows, int $cols): array
    {
        $rowClues = [];
        for ($r = 0; $r < $rows; $r++) {
            $line = [];
            for ($c = 0; $c < $cols; $c++) {
                $line[] = $solution[$r * $cols + $c] === '1' ? 1 : 0;
            }
            $rowClues[] = NonogramSolver::clues($line);
        }
        $colClues = [];
        for ($c = 0; $c < $cols; $c++) {
            $line = [];
            for ($r = 0; $r < $rows; $r++) {
                $line[] = $solution[$r * $cols + $c] === '1' ? 1 : 0;
            }
            $colClues[] = NonogramSolver::clues($line);
        }

        return [$rowClues, $colClues];
    }

    /**
     * Mean luma (0-255) of each cell, row-major.
     *
     * @return list<int>
     */
    public static function lumas(GdImage $image, Bounds $crop, int $rows, int $cols): array
    {
        $cell = intdiv($crop->width, $cols);
        $samples = min(self::SAMPLES, $cell);
        $out = [];
        $truecolor = imageistruecolor($image);
        for ($r = 0; $r < $rows; $r++) {
            for ($c = 0; $c < $cols; $c++) {
                $sum = 0;
                for ($sy = 0; $sy < $samples; $sy++) {
                    $y = $crop->y + $r * $cell + intdiv((2 * $sy + 1) * $cell, 2 * $samples);
                    for ($sx = 0; $sx < $samples; $sx++) {
                        $x = $crop->x + $c * $cell + intdiv((2 * $sx + 1) * $cell, 2 * $samples);
                        $rgba = imagecolorat($image, $x, $y);
                        if ($rgba === false) {
                            throw new InvalidArgumentException("Pixel {$x},{$y} is outside the image.");
                        }
                        if (!$truecolor) {
                            $color = imagecolorsforindex($image, $rgba);
                            $rgba = ($color['alpha'] << 24) | ($color['red'] << 16) | ($color['green'] << 8) | $color['blue'];
                        }
                        $alpha = ($rgba >> 24) & 0x7F;
                        $luma = 2126 * (($rgba >> 16) & 0xFF) + 7152 * (($rgba >> 8) & 0xFF) + 722 * ($rgba & 0xFF);
                        // Composite over white: alpha 127 is fully transparent in GD.
                        $sum += intdiv($luma * (127 - $alpha) + 2_550_000 * $alpha, 127);
                    }
                }
                $out[] = intdiv($sum, 10000 * $samples * $samples);
            }
        }

        return $out;
    }

    /**
     * Otsu's threshold on 0-255 values: the level that maximises the
     * between-class variance (cells below it are dark).
     *
     * @param list<int> $values
     */
    public static function otsu(array $values): int
    {
        $histogram = array_fill(0, 256, 0);
        foreach ($values as $value) {
            $histogram[$value]++;
        }
        $total = count($values);
        $sum = 0;
        foreach ($histogram as $level => $count) {
            $sum += $level * $count;
        }
        $best = 128;
        $bestVariance = -1.0;
        $weightDark = 0;
        $sumDark = 0;
        for ($level = 1; $level < 256; $level++) {
            $weightDark += $histogram[$level - 1];
            $sumDark += ($level - 1) * $histogram[$level - 1];
            $weightLight = $total - $weightDark;
            if ($weightDark === 0 || $weightLight === 0) {
                continue;
            }
            $meanDark = $sumDark / $weightDark;
            $meanLight = ($sum - $sumDark) / $weightLight;
            $variance = $weightDark * $weightLight * ($meanDark - $meanLight) ** 2;
            if ($variance > $bestVariance) {
                $bestVariance = $variance;
                $best = $level;
            }
        }

        return $best;
    }
}
