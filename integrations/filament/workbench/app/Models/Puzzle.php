<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ImagePuzzle\Filament\Concerns\HasImagePuzzle;
use ImagePuzzle\Filament\Contracts\PuzzleRecord;

/**
 * @property int $id
 * @property int $team_id
 * @property string $name
 * @property string|null $puzzle_source
 * @property array<string, mixed>|null $puzzle_config
 * @property string|null $puzzle_definition
 * @property string|null $puzzle_status
 * @property string|null $puzzle_error
 */
final class Puzzle extends Model implements PuzzleRecord
{
    use HasImagePuzzle;

    protected $fillable = ['team_id', 'name', 'puzzle_source', 'puzzle_config'];

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['puzzle_config' => 'array'];
    }
}
