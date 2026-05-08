<?php

namespace App\Policies;

use App\Models\Stage;
use App\Models\StageQualification;
use App\Models\Tournament;
use App\Models\User;

class StageQualificationPolicy
{
    public function view(?User $user, StageQualification $qualification): bool
    {
        return true;
    }

    /**
     * Authorize call shape:
     *   $this->authorize('create', [StageQualification::class, $stage]);
     */
    public function create(User $user, Stage $stage): bool
    {
        return $this->isTournamentAdmin($user, $stage->tournament);
    }

    public function delete(User $user, StageQualification $qualification): bool
    {
        return $this->isTournamentAdmin($user, $qualification->target->tournament);
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
