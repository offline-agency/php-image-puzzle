<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use ImagePuzzle\Filament\PuzzleColumns;

/**
 * The users, cache and jobs tables come from Testbench's default migrations.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('teams', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });
        Schema::create('team_user', static function (Blueprint $table): void {
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['team_id', 'user_id']);
        });
        Schema::create('puzzles', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            PuzzleColumns::define($table);
            $table->timestamps();
        });
        Schema::create('notifications', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['notifications', 'puzzles', 'team_user', 'teams'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
