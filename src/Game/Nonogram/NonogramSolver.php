<?php

declare(strict_types=1);

namespace ImagePuzzle\Game\Nonogram;

/**
 * Bounded nonogram solver used to classify uniqueness.
 *
 * Line solving: for one row or column, a dynamic program over (cell, block)
 * finds every placement of the clue blocks consistent with the known cells,
 * and fixes the cells that are filled (or empty) in all of them. Lines are
 * re-solved until nothing changes. When cells remain unknown, the search
 * branches on the first one (filled first, then empty) and keeps counting
 * solutions until it finds a second one.
 *
 * The budget counts line solves, so a result is deterministic: the same
 * clues and budget always give the same answer on every machine.
 * "unverified" means the budget ran out before uniqueness was decided; it
 * is never reported as unique.
 */
final class NonogramSolver
{
    public const UNIQUE = 'unique';
    public const MULTIPLE = 'multiple';
    public const UNVERIFIED = 'unverified';
    public const NONE = 'none';
    public const DEFAULT_BUDGET = 20_000;

    private int $work = 0;

    /** @var list<string> */
    private array $solutions = [];

    /**
     * @param list<list<int>> $rowClues
     * @param list<list<int>> $colClues
     */
    public function __construct(
        private readonly array $rowClues,
        private readonly array $colClues,
        private readonly int $budget = self::DEFAULT_BUDGET,
    ) {
    }

    /**
     * @return array{status: string, solutions: list<string>, work: int}
     *                                                                   status: unique, multiple, none or unverified; solutions: up to two grids
     *                                                                   as strings of "1" (filled) and "0"; work: line solves used
     */
    public function classify(): array
    {
        $this->work = 0;
        $this->solutions = [];
        $rows = count($this->rowClues);
        $cols = count($this->colClues);
        $grid = array_fill(0, $rows * $cols, -1);
        $finished = $this->search($grid, $rows, $cols);
        $count = count($this->solutions);
        $status = match (true) {
            $count >= 2 => self::MULTIPLE,
            !$finished => self::UNVERIFIED,
            $count === 1 => self::UNIQUE,
            default => self::NONE,
        };

        return ['status' => $status, 'solutions' => $this->solutions, 'work' => $this->work];
    }

    /**
     * Run-length clues of a line of 0/1 values.
     *
     * @param list<int> $line
     * @return list<int>
     */
    public static function clues(array $line): array
    {
        $clues = [];
        $run = 0;
        foreach ($line as $cell) {
            if ($cell === 1) {
                $run++;
            } elseif ($run > 0) {
                $clues[] = $run;
                $run = 0;
            }
        }
        if ($run > 0) {
            $clues[] = $run;
        }

        return $clues;
    }

    /**
     * Fixes what the clues force in one line, or returns null on a
     * contradiction. Cells: -1 unknown, 0 empty, 1 filled.
     *
     * @param list<int> $cells
     * @param list<int> $clues
     * @return list<int>|null
     */
    public static function solveLine(array $cells, array $clues): ?array
    {
        $n = count($cells);
        $k = count($clues);
        // $fit[$i][$j]: blocks j.. fit in cells i.. (cell i may start a block).
        $fit = array_fill(0, $n + 2, array_fill(0, $k + 1, false));
        // Last filled cell, to check "no filled cell from i on".
        $lastFilled = -1;
        foreach ($cells as $i => $cell) {
            if ($cell === 1) {
                $lastFilled = $i;
            }
        }
        // Next empty cell at or after i (for block placement checks).
        $nextEmpty = array_fill(0, $n + 1, $n);
        for ($i = $n - 1; $i >= 0; $i--) {
            $nextEmpty[$i] = $cells[$i] === 0 ? $i : $nextEmpty[$i + 1];
        }
        for ($i = $n + 1; $i >= 0; $i--) {
            $fit[$i][$k] = $i > $lastFilled;
        }
        for ($j = $k - 1; $j >= 0; $j--) {
            $len = $clues[$j];
            for ($i = $n; $i >= 0; $i--) {
                $ok = false;
                if ($i < $n && $cells[$i] !== 1 && $fit[$i + 1][$j]) {
                    $ok = true;
                }
                if (!$ok && $i + $len <= $n && $nextEmpty[$i] >= $i + $len) {
                    $end = $i + $len;
                    if ($end === $n) {
                        $ok = $j === $k - 1;
                    } elseif ($cells[$end] !== 1) {
                        $ok = $fit[$end + 1][$j + 1];
                    }
                }
                $fit[$i][$j] = $ok;
            }
        }
        if (!$fit[0][0]) {
            return null;
        }
        // Walk every reachable placement and record what each cell can be.
        $canFill = array_fill(0, $n, false);
        $canEmpty = array_fill(0, $n, false);
        $reached = [0 => [0 => true]];
        for ($i = 0; $i <= $n; $i++) {
            foreach (array_keys($reached[$i] ?? []) as $j) {
                if ($j === $k) {
                    for ($c = $i; $c < $n; $c++) {
                        $canEmpty[$c] = true;
                    }
                    continue;
                }
                if ($i < $n && $cells[$i] !== 1 && $fit[$i + 1][$j]) {
                    $canEmpty[$i] = true;
                    $reached[$i + 1][$j] = true;
                }
                $len = $clues[$j];
                if ($i + $len <= $n && $nextEmpty[$i] >= $i + $len) {
                    $end = $i + $len;
                    if ($end === $n && $j === $k - 1) {
                        for ($c = $i; $c < $end; $c++) {
                            $canFill[$c] = true;
                        }
                        $reached[$n + 1][$k] = true;
                    } elseif ($end < $n && $cells[$end] !== 1 && $fit[$end + 1][$j + 1]) {
                        for ($c = $i; $c < $end; $c++) {
                            $canFill[$c] = true;
                        }
                        $canEmpty[$end] = true;
                        $reached[$end + 1][$j + 1] = true;
                    }
                }
            }
        }
        $out = $cells;
        for ($i = 0; $i < $n; $i++) {
            if (!$canFill[$i] && !$canEmpty[$i]) {
                return null;
            }
            if ($cells[$i] === -1) {
                $out[$i] = $canFill[$i] && $canEmpty[$i] ? -1 : ($canFill[$i] ? 1 : 0);
            }
        }

        return array_values($out);
    }

    /**
     * Depth-first search; false when the budget ran out.
     *
     * @param list<int> $grid
     */
    private function search(array $grid, int $rows, int $cols): bool
    {
        $grid = $this->propagate($grid, $rows, $cols);
        if ($grid === false) {
            return false;
        }
        if ($grid === null) {
            return true;
        }
        $unknown = array_search(-1, $grid, true);
        if ($unknown === false) {
            $this->solutions[] = implode('', $grid);

            return true;
        }
        foreach ([1, 0] as $value) {
            $branch = $grid;
            $branch[$unknown] = $value;
            if (!$this->search(array_values($branch), $rows, $cols)) {
                return false;
            }
            if (count($this->solutions) >= 2) {
                return true;
            }
        }

        return true;
    }

    /**
     * Line solving to a fixpoint: the grid, null on a contradiction, false
     * when the budget ran out.
     *
     * @param list<int> $grid
     * @return list<int>|null|false
     */
    private function propagate(array $grid, int $rows, int $cols): array|null|false
    {
        $dirtyRows = array_fill(0, $rows, true);
        $dirtyCols = array_fill(0, $cols, true);
        do {
            $changed = false;
            for ($r = 0; $r < $rows; $r++) {
                if (!$dirtyRows[$r]) {
                    continue;
                }
                $dirtyRows[$r] = false;
                if (++$this->work > $this->budget) {
                    return false;
                }
                $line = array_slice($grid, $r * $cols, $cols);
                $solved = self::solveLine($line, $this->rowClues[$r]);
                if ($solved === null) {
                    return null;
                }
                foreach ($solved as $c => $value) {
                    if ($line[$c] !== $value) {
                        $grid[$r * $cols + $c] = $value;
                        $dirtyCols[$c] = true;
                        $changed = true;
                    }
                }
            }
            for ($c = 0; $c < $cols; $c++) {
                if (!$dirtyCols[$c]) {
                    continue;
                }
                $dirtyCols[$c] = false;
                if (++$this->work > $this->budget) {
                    return false;
                }
                $line = [];
                for ($r = 0; $r < $rows; $r++) {
                    $line[] = $grid[$r * $cols + $c];
                }
                $solved = self::solveLine($line, $this->colClues[$c]);
                if ($solved === null) {
                    return null;
                }
                foreach ($solved as $r => $value) {
                    if ($line[$r] !== $value) {
                        $grid[$r * $cols + $c] = $value;
                        $dirtyRows[$r] = true;
                        $changed = true;
                    }
                }
            }
        } while ($changed);

        return array_values($grid);
    }
}
