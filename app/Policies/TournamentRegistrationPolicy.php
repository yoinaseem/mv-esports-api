<?php

namespace App\Policies;

use App\Models\Player;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentRegistration;
use App\Models\User;

/**
 * Authorization for TournamentRegistration. The "owner" concept here is
 * intentionally broad: the registrant (whoever clicked register), the
 * participant owner (player.user_id, team creator, or active team
 * captain), or a tournament admin (host / creator / system role) all
 * count as "may act on this registration."
 */
class TournamentRegistrationPolicy
{
    public function view(?User $user, TournamentRegistration $registration): bool
    {
        return true; // Public reads scoped by parent tournament's view policy.
    }

    /**
     * Authorize call shape:
     *   $this->authorize('register', [TournamentRegistration::class, $tournament, $type, $id]);
     */
    public function register(User $user, Tournament $tournament, string $participantType, int $participantId): bool
    {
        $participant = match ($participantType) {
            'team'   => Team::find($participantId),
            'player' => Player::find($participantId),
            default  => null,
        };

        if ($participant === null) {
            return false;
        }

        if ($participantType === 'player') {
            return $participant->user_id === $user->id;
        }

        // team
        return $participant->isCreatedBy($user) || $participant->isCaptainedBy($user);
    }

    /**
     * Update a registration. Three paths into "yes":
     *  - Tournament admin (host / creator / system role).
     *  - The registrant (whoever clicked register).
     *  - The participant owner (player.user_id, team creator, or active
     *    captain) — even if they didn't perform the original registration,
     *    they should be able to withdraw on the participant's behalf.
     * Whether they can change status vs only-withdraw is decided in the
     * controller, not here.
     */
    public function update(User $user, TournamentRegistration $registration): bool
    {
        return $this->isAdminOf($user, $registration->tournament)
            || $registration->registered_by_user_id === $user->id
            || $this->isParticipantOwner($user, $registration);
    }

    /**
     * Hard-delete. Tournament admin only — owners withdraw via PATCH.
     */
    public function delete(User $user, TournamentRegistration $registration): bool
    {
        return $this->isAdminOf($user, $registration->tournament);
    }

    private function isAdminOf(User $user, Tournament $tournament): bool
    {
        if ($user->hasAnyRole(['system_manager', 'superadmin'])) {
            return true;
        }

        if ($tournament->created_by_user_id === $user->id) {
            return true;
        }

        return $tournament->host !== null && $tournament->host->user_id === $user->id;
    }

    private function isParticipantOwner(User $user, TournamentRegistration $registration): bool
    {
        // $registration->participant uses Eloquent's morphTo relation, which
        // caches the result on the model instance after first access. Calling
        // this method twice in the same request hits the cache the second
        // time. For batch authorization (e.g., a future filtered list of
        // registrations) the controller should pre-load with
        // ->with('participant') to avoid N+1.
        $participant = $registration->participant;

        if ($participant === null) {
            return false;
        }

        if ($registration->participant_type === 'player') {
            return $participant->user_id === $user->id;
        }

        return $participant->isCreatedBy($user) || $participant->isCaptainedBy($user);
    }
}
