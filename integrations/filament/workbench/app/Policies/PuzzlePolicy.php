<?php

declare(strict_types=1);

namespace Workbench\App\Policies;

use Workbench\App\Models\Puzzle;
use Workbench\App\Models\User;

/**
 * Team members may see and edit their team's puzzles. This is the tenant
 * check that also guards playing, assets and source previews, which are
 * served outside the panel.
 */
final class PuzzlePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(?User $user, Puzzle $puzzle): bool
    {
        return $user !== null && $user->teams()->whereKey($puzzle->team_id)->exists();
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Puzzle $puzzle): bool
    {
        return $this->view($user, $puzzle);
    }

    public function delete(User $user, Puzzle $puzzle): bool
    {
        return $this->view($user, $puzzle);
    }
}
