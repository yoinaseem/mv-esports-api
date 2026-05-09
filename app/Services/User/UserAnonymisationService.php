<?php

namespace App\Services\User;

use App\Models\MatchEvent;
use App\Models\Organization;
use App\Models\Tournament;
use App\Models\TournamentHost;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Anonymises a user account on deletion. Per DESIGN.md §11.5: replace the
 * gamertag with a placeholder, set `players.user_id = null`, soft-delete
 * the user, and preserve match results / registrations for tournament
 * integrity.
 *
 * The single public method `anonymise($user)` is called from both the
 * self-delete and admin-delete paths. Wrapped in DB::transaction so any
 * failure rolls everything back.
 *
 * Throws \DomainException with a clear message if the user owns active
 * organisations — those need ownership transfer first.
 *
 * --- Hard-purge warning ---
 * The schema has `cascadeOnDelete` on `tournament_registrations.registered_by_user_id`
 * and `tournament_hosts.user_id`. Calling `User::forceDelete()` would wipe
 * those rows along with the user, violating DESIGN.md §11.5's promise to
 * preserve match results. NEVER force-delete a User — always go through
 * this service for soft-delete + anonymisation.
 */
class UserAnonymisationService
{
    public function anonymise(User $user): void
    {
        // Precondition check before opening the transaction — saves a
        // round-trip on the failure path.
        $this->assertNoActiveOrgOwnership($user);

        DB::transaction(function () use ($user) {
            // 1. Anonymise the user's own row. Email gets a unique suffix
            //    so a future user registering with the original email
            //    doesn't hit a soft-deleted-row collision. Password is
            //    set to a hash of a random string so the column's NOT
            //    NULL constraint is satisfied AND no one can authenticate
            //    as this account (the random plaintext is never exposed).
            $user->update([
                'name'         => '[deleted user]',
                'display_name' => '[deleted user]',
                'email'        => "deleted-{$user->id}@anonymised.local",
                'password'     => Hash::make(Str::random(40)),
            ]);

            // 2. Anonymise the player rows (and detach from user) per
            //    DESIGN §11.5.
            $user->players()->update([
                'user_id'  => null,
                'gamertag' => '[deleted user]',
            ]);

            // 3. Anonymise the tournament_hosts row if present. Don't
            //    delete it — past tournaments still reference this host
            //    via host_id and the row keeps the audit trail intact.
            //    Set status to suspended so any "restore user" path in
            //    the future doesn't accidentally re-grant host capability.
            if ($user->tournamentHost) {
                $user->tournamentHost->update([
                    'display_name' => '[deleted user]',
                    'bio'          => null,
                    'status'       => 'suspended',
                ]);
            }

            // 4. Null FKs that point at this user. Schema has nullOnDelete
            //    for the hard-delete path; we replicate it for soft-delete
            //    so the ID link is severed even though the User row stays.
            Tournament::where('created_by_user_id', $user->id)
                ->update(['created_by_user_id' => null]);
            Tournament::where('approved_by_user_id', $user->id)
                ->update(['approved_by_user_id' => null]);
            TournamentHost::where('approved_by_user_id', $user->id)
                ->update(['approved_by_user_id' => null]);
            MatchEvent::where('created_by_user_id', $user->id)
                ->update(['created_by_user_id' => null]);

            // tournament_registrations.registered_by_user_id is NOT NULL
            // with cascadeOnDelete on hard-delete. On soft-delete the FK
            // stays valid (User row remains); the registration shows the
            // anonymised "[deleted user]" name via the relation.

            // 5. Strip roles and direct permissions. A future "restore
            //    user" path (not built yet) won't accidentally bring the
            //    user back with their old privileges.
            $user->syncRoles([]);
            $user->syncPermissions([]);

            // 6. Revoke all Sanctum tokens — caller is logged out
            //    everywhere. Both self-delete and admin-delete benefit.
            $user->tokens()->delete();

            // 7. Soft-delete.
            $user->delete();
        });
    }

    private function assertNoActiveOrgOwnership(User $user): void
    {
        // Organization uses SoftDeletes, so the default scope already
        // excludes trashed rows — no explicit whereNull needed.
        $count = Organization::where('owner_user_id', $user->id)->count();
        if ($count > 0) {
            throw new \DomainException(sprintf(
                'Cannot delete: user owns %d active organisation(s). Transfer ownership first.',
                $count,
            ));
        }
    }
}
