<?php

namespace App\Policies;

use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Models\User;

/**
 * Authorization for Tournament resource. Used via $this->authorize() in
 * TournamentController and via Gate::allows / @can on the frontend's
 * permission probes.
 *
 * Convention: methods return true / false based on caller authz only.
 * State preconditions ("tournament is currently in DraftPendingReview")
 * stay in the controller as explicit abort_unless calls — that keeps
 * the policy from lying about why something was rejected (a 403 vs a
 * 422 carries different semantic for the frontend).
 */
class TournamentPolicy
{
    /**
     * Visibility for index lists and show endpoints. Anonymous viewers
     * see only "public" states (DESIGN.md §6); creators and managers
     * additionally see their own drafts.
     */
    public function view(?User $user, Tournament $tournament): bool
    {
        if ($tournament->status->isPublic()) {
            return true;
        }

        // Drafts: visible to creator + system roles.
        return $user !== null
            && ($tournament->created_by_user_id === $user->id
                || $user->hasAnyRole(['system_manager', 'superadmin']));
    }

    /**
     * Patch non-status fields. Hosts of the tournament + system roles.
     * The created_by_user_id catches the manager-direct-create path
     * (where host_id is null but created_by is set).
     */
    public function update(User $user, Tournament $tournament): bool
    {
        return $this->isHostOrManager($user, $tournament);
    }

    /**
     * Soft-delete the tournament. Creator or superadmin only — hosts
     * who didn't create it can't archive it. State precondition (only
     * DraftPendingReview / Cancelled allowed) stays in the controller.
     */
    public function delete(User $user, Tournament $tournament): bool
    {
        return $tournament->created_by_user_id === $user->id
            || $user->hasRole('superadmin');
    }

    /**
     * Approve a draft-pending-review tournament. Manager / superadmin only.
     */
    public function approve(User $user, Tournament $tournament): bool
    {
        return $user->hasAnyRole(['system_manager', 'superadmin']);
    }

    public function reject(User $user, Tournament $tournament): bool
    {
        return $user->hasAnyRole(['system_manager', 'superadmin']);
    }

    public function openRegistration(User $user, Tournament $tournament): bool
    {
        return $this->isHostOrManager($user, $tournament);
    }

    public function closeRegistration(User $user, Tournament $tournament): bool
    {
        return $this->isHostOrManager($user, $tournament);
    }

    public function cancel(User $user, Tournament $tournament): bool
    {
        return $this->isHostOrManager($user, $tournament);
    }

    private function isHostOrManager(User $user, Tournament $tournament): bool
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
