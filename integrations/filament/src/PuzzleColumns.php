<?php

declare(strict_types=1);

namespace ImagePuzzle\Filament;

use Illuminate\Database\Schema\Blueprint;

/**
 * Columns used by the HasImagePuzzle trait:
 *
 *     Schema::table('puzzles', fn (Blueprint $table) => PuzzleColumns::define($table));
 */
final class PuzzleColumns
{
    public static function define(Blueprint $table): void
    {
        $table->string('puzzle_source')->nullable();
        $table->json('puzzle_config')->nullable();
        $table->string('puzzle_definition')->nullable();
        $table->string('puzzle_status', 16)->nullable();
        $table->text('puzzle_error')->nullable();
    }
}
