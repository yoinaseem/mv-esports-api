<?php

namespace App\Policies;

use App\Enums\TournamentStatus;
use App\Models\Stage;
use App\Models\Tournament;
use App\Models\User;

/**
 * Authorization for Stage. Stages live under a tournament; the caller
 * authz delegates to "is the caller a tournament admin (host / creator
 * / system role)?" The state-precondition ("structure is locked once
 * registration_closed") stays in the controller as `abort_unless` —
 * it's a 422, not a 403.
 */
class StagePolicy
{
    public function view(?User $user, Stage $stage): bool
    {
        return true; // Stages are public; gating happens at the tournament level.
    }

    public function update(User $user, Stage $stage): bool
    {
        return $this->isTournamentAdmin($user, $stage->tournament);
    }

    public function delete(User $user, Stage $stage): bool
    {
        return $this->isTournamentAdmin($user, $stage->tournament);
    }

    /**
     * Authorize call shape:
     *   $this->authorize('create', [Stage::class, $tournament]);
     */
    public function create(User $user, Tournament $tournament): bool
    {
        return $this->isTournamentAdmin($user, $tournament);
    }

    public function reorder(User $user, Tournament $tournament): bool
    {
        return $this->isTournamentAdmin($user, $tournament);
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

    /**
     * Helper used by the controller to decide whether structure mutations
     * are allowed. Unlocked while pre-build — the host's last chance to
     * fix a misconfigured stage is between RegistrationClosed and
     * seed-and-build, so RegistrationClosed is in. Locked once InProgress
     * because matches exist by then; changing the bracket retroactively
     * breaks advancement.
     */
    public static function structureUnlocked(Tournament $tournament): bool
    {
        return in_array($tournament->status, [
            TournamentStatus::DraftPendingReview,
            TournamentStatus::Draft,
            TournamentStatus::RegistrationOpen,
            TournamentStatus::RegistrationClosed,
        ], true);
    }
}
