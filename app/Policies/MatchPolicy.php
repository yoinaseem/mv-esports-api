<?php

namespace App\Policies;

use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;

/**
 * Authorization for matches. Matches are created by the bracket
 * generator (commit 8) and don't get hard-deleted (cancellation goes
 * through the status enum), so no `create` or `delete` here.
 */
class MatchPolicy
{
    public function view(?User $user, TournamentMatch $match): bool
    {
        return true; // Matches inherit the parent tournament's visibility.
    }

    public function update(User $user, TournamentMatch $match): bool
    {
        return $this->isTournamentAdmin($user, $match->stage->tournament);
    }

    public function walkover(User $user, TournamentMatch $match): bool
    {
        return $this->isTournamentAdmin($user, $match->stage->tournament);
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
