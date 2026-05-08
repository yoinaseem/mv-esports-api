<?php

namespace App\Policies;

use App\Models\MatchGame;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;

class MatchGamePolicy
{
    public function view(?User $user, MatchGame $game): bool
    {
        return true;
    }

    /**
     * Authorize call shape:
     *   $this->authorize('create', [MatchGame::class, $match]);
     */
    public function create(User $user, TournamentMatch $match): bool
    {
        return $this->isTournamentAdmin($user, $match->stage->tournament);
    }

    public function update(User $user, MatchGame $game): bool
    {
        return $this->isTournamentAdmin($user, $game->match->stage->tournament);
    }

    public function delete(User $user, MatchGame $game): bool
    {
        return $this->isTournamentAdmin($user, $game->match->stage->tournament);
    }

    private function isTournamentAdmin(User $user, Tournament $tournament): bool
    {
        if ($user->hasAnyRole(['system_manager', 'superadmin'])) {
            return true;
        }

        if ($tournament->created_by_user_id === $user->id) {
            return true;
        }

        return $tournament->host !== null && $tournament->host->user_id === $user->id;
    }
}
