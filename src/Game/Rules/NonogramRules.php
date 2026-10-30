<?php

declare(strict_types=1);

namespace ImagePuzzle\Game\Rules;

use ImagePuzzle\Exception\IllegalActionException;
use ImagePuzzle\Exception\InvalidDefinitionException;
use ImagePuzzle\Game\Doc;
use ImagePuzzle\Game\GameDefinition;
use ImagePuzzle\Game\GameKind;
use ImagePuzzle\Game\Nonogram\NonogramGenerator;
use ImagePuzzle\Game\Nonogram\NonogramSolver;

/**
 * Nonogram: fill the cells of a rows x cols grid so that every row and
 * column shows its clue (the lengths of its runs of filled cells, in order).
 *
 * Definition: no tiles; rules.solution is the generated grid (row-major
 * "1" filled / "0" empty), rules.clues its row and column clues,
 * rules.uniqueness the solver's verdict (unique, multiple or unverified),
 * rules.threshold and rules.invert the generation parameters. The board
 * crop is the image region, revealed when the puzzle is complete.
 *
 * Board: {"cells": "..."}: one character per cell, row-major: "1" filled,
 * "x" marked empty, "." unknown. Action {"type": "mark", "cell": i,
 * "state": "filled" | "crossed" | "unknown"}. The puzzle is complete when
 * the filled cells satisfy every clue (crossed and unknown cells count as
 * empty), so any grid matching the clues completes a non-unique puzzle.
 * The hint marks the first cell that disagrees with rules.solution.
 */
final class NonogramRules implements ModeRules
{
    public const STATES = ['filled' => '1', 'crossed' => 'x', 'unknown' => '.'];
    private const UNIQUENESS = [NonogramSolver::UNIQUE, NonogramSolver::MULTIPLE, NonogramSolver::UNVERIFIED];

    public function kind(): GameKind
    {
        return GameKind::Nonogram;
    }

    public function actionTypes(): array
    {
        return ['mark'];
    }

    public function capabilities(): array
    {
        return ['undo' => true, 'redo' => true, 'reset' => true, 'shuffle' => false, 'hint' => true];
    }

    public function validateDefinition(GameDefinition $definition): void
    {
        $rows = $definition->rows;
        $cols = $definition->cols;
        if ($rows < NonogramGenerator::MIN_SIDE || $cols < NonogramGenerator::MIN_SIDE || $rows > NonogramGenerator::MAX_SIDE || $cols > NonogramGenerator::MAX_SIDE) {
            throw new InvalidDefinitionException('A nonogram must be between ' . NonogramGenerator::MIN_SIDE . 'x' . NonogramGenerator::MIN_SIDE . ' and ' . NonogramGenerator::MAX_SIDE . 'x' . NonogramGenerator::MAX_SIDE . " cells, got {$rows}x{$cols}.");
        }
        if ($definition->tiles !== []) {
            throw new InvalidDefinitionException('A nonogram has no tiles.');
        }
        if ($definition->crop->width % $cols !== 0 || $definition->crop->height % $rows !== 0 || intdiv($definition->crop->width, $cols) !== intdiv($definition->crop->height, $rows)) {
            throw new InvalidDefinitionException('board.crop must divide into square cells.');
        }
        $rules = $definition->rules;
        Doc::keys($rules, ['solution', 'clues', 'uniqueness', 'threshold', 'invert'], 'definition.rules');
        $solution = self::solution($definition);
        if (!str_contains($solution, '1')) {
            throw new InvalidDefinitionException('definition.rules.solution has no filled cell.');
        }
        $clues = Doc::object($rules['clues'] ?? null, 'definition.rules.clues');
        Doc::keys($clues, ['rows', 'cols'], 'definition.rules.clues');
        [$rowClues, $colClues] = NonogramGenerator::cluesOf($solution, $rows, $cols);
        if (self::clueLists($clues['rows'] ?? null, $rows, $cols, 'rows') !== $rowClues || self::clueLists($clues['cols'] ?? null, $cols, $rows, 'cols') !== $colClues) {
            throw new InvalidDefinitionException('definition.rules.clues do not match rules.solution.');
        }
        if (!in_array($rules['uniqueness'] ?? null, self::UNIQUENESS, true)) {
            throw new InvalidDefinitionException('definition.rules.uniqueness must be unique, multiple or unverified.');
        }
        Doc::int($rules, 'threshold', 'definition.rules', 1, 255);
        Doc::bool($rules, 'invert', 'definition.rules');
    }

    public function solvedBoard(GameDefinition $definition): array
    {
        return ['cells' => strtr(self::solution($definition), ['0' => 'x'])];
    }

    /** Every cell unknown: the seed does not change a nonogram. */
    public function scramble(GameDefinition $definition, int $seed): array
    {
        return ['cells' => str_repeat('.', $definition->rows * $definition->cols)];
    }

    public function validateBoard(GameDefinition $definition, mixed $board): array
    {
        $board = Doc::object($board, 'board');
        Doc::keys($board, ['cells'], 'board');
        $cells = Doc::stringValue($board['cells'] ?? null, 'board.cells', NonogramGenerator::MAX_SIDE ** 2);
        if (strlen($cells) !== $definition->rows * $definition->cols || preg_match('/^[1x.]*$/', $cells) !== 1) {
            throw new InvalidDefinitionException('board.cells must have one of "1", "x" or "." per cell.');
        }

        return ['cells' => $cells];
    }

    public function apply(GameDefinition $definition, array $board, array $action): array
    {
        $type = Doc::string($action, 'type', 'action', 32);
        if ($type !== 'mark') {
            throw new IllegalActionException("The nonogram mode does not support \"{$type}\" actions.");
        }
        Doc::keys($action, ['type', 'cell', 'state'], 'action');
        $count = $definition->rows * $definition->cols;
        $cell = Doc::int($action, 'cell', 'action', 0, PHP_INT_MAX);
        if ($cell >= $count) {
            throw new IllegalActionException("Cell {$cell} is outside the {$definition->rows}x{$definition->cols} grid.");
        }
        $state = Doc::string($action, 'state', 'action', 16);
        if (!isset(self::STATES[$state])) {
            throw new InvalidDefinitionException('action.state must be filled, crossed or unknown.');
        }
        $cells = self::cells($board);
        if ($cells[$cell] === self::STATES[$state]) {
            throw new IllegalActionException("Cell {$cell} is already {$state}.");
        }
        $cells[$cell] = self::STATES[$state];

        return ['cells' => $cells];
    }

    public function legalActions(GameDefinition $definition, array $board): array
    {
        $out = [];
        $cells = self::cells($board);
        $length = strlen($cells);
        for ($i = 0; $i < $length; $i++) {
            foreach (self::STATES as $state => $char) {
                if ($cells[$i] !== $char) {
                    $out[] = ['type' => 'mark', 'cell' => $i, 'state' => $state];
                }
            }
        }

        return $out;
    }

    public function isComplete(GameDefinition $definition, array $board): bool
    {
        $filled = strtr(self::cells($board), ['x' => '0', '.' => '0']);
        $rules = Doc::object($definition->rules['clues'] ?? null, 'definition.rules.clues');

        return NonogramGenerator::cluesOf($filled, $definition->rows, $definition->cols) === [$rules['rows'] ?? null, $rules['cols'] ?? null];
    }

    /** Fraction of rows and columns whose filled cells already match their clue. */
    public function progress(GameDefinition $definition, array $board): float
    {
        $filled = strtr(self::cells($board), ['x' => '0', '.' => '0']);
        [$rows, $cols] = NonogramGenerator::cluesOf($filled, $definition->rows, $definition->cols);
        $clues = Doc::object($definition->rules['clues'] ?? null, 'definition.rules.clues');
        $expectedRows = Doc::list($clues['rows'] ?? null, 'clues.rows');
        $expectedCols = Doc::list($clues['cols'] ?? null, 'clues.cols');
        $matching = 0;
        foreach ($rows as $i => $clue) {
            $matching += $clue === ($expectedRows[$i] ?? null) ? 1 : 0;
        }
        foreach ($cols as $i => $clue) {
            $matching += $clue === ($expectedCols[$i] ?? null) ? 1 : 0;
        }

        return $matching / ($definition->rows + $definition->cols);
    }

    /**
     * Marks the first cell (row-major) whose state disagrees with the
     * generated solution: a wrong or missing fill, or a filled cell that
     * should be empty. Unknown cells that should stay empty are not hinted.
     */
    public function hint(GameDefinition $definition, array $board): ?array
    {
        if ($this->isComplete($definition, $board)) {
            return null;
        }
        $cells = self::cells($board);
        $solution = self::solution($definition);
        $length = strlen($solution);
        for ($i = 0; $i < $length; $i++) {
            if ($solution[$i] === '1' && $cells[$i] !== '1') {
                return ['type' => 'mark', 'cell' => $i, 'state' => 'filled'];
            }
            if ($solution[$i] === '0' && $cells[$i] === '1') {
                return ['type' => 'mark', 'cell' => $i, 'state' => 'crossed'];
            }
        }

        return null;
    }

    private static function solution(GameDefinition $definition): string
    {
        $solution = Doc::stringValue($definition->rules['solution'] ?? null, 'definition.rules.solution', NonogramGenerator::MAX_SIDE ** 2);
        if (strlen($solution) !== $definition->rows * $definition->cols || preg_match('/^[01]+$/', $solution) !== 1) {
            throw new InvalidDefinitionException('definition.rules.solution must have one "1" or "0" per cell.');
        }

        return $solution;
    }

    /**
     * @param array<string, mixed> $board
     */
    private static function cells(array $board): string
    {
        return Doc::stringValue($board['cells'] ?? null, 'board.cells', NonogramGenerator::MAX_SIDE ** 2);
    }

    /**
     * @return list<list<int>>
     */
    private static function clueLists(mixed $value, int $count, int $length, string $axis): array
    {
        $out = [];
        foreach (Doc::list($value, "definition.rules.clues.{$axis}", $count) as $i => $line) {
            $clue = [];
            foreach (Doc::list($line, "definition.rules.clues.{$axis}[{$i}]", $length) as $j => $run) {
                $clue[] = Doc::intValue($run, "definition.rules.clues.{$axis}[{$i}][{$j}]", 1, $length);
            }
            $out[] = $clue;
        }

        return $out;
    }
}
