<?php

namespace App\Http\Controllers;

use App\Enums\RegistrationStatus;
use App\Enums\TournamentStatus;
use App\Http\Requests\TournamentRegistration\CreateRegistrationRequest;
use App\Http\Requests\TournamentRegistration\UpdateRegistrationRequest;
use App\Http\Resources\TournamentRegistrationResource;
use App\Models\Tournament;
use App\Models\TournamentRegistration;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class TournamentRegistrationController extends Controller
{
    /**
     * tournamentRegistration.index
     * Public list scoped to the parent tournament. Optional ?status filter.
     */
    public function index(Request $request, Tournament $tournament): AnonymousResourceCollection
    {
        $registrations = $tournament->registrations()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderBy('registered_at')
            ->get();

        return TournamentRegistrationResource::collection($registrations);
    }

    /**
     * tournamentRegistration.store
     *
     * Layered validation:
     *  - CreateRegistrationRequest: input shape + cross-input invariants
     *    (participant_type matches tournament, participant exists with
     *    matching game_id, no participant double-active, no same-user
     *    double-register).
     *  - TournamentRegistrationPolicy::register: caller owns the participant.
     *  - Inside DB::transaction with a Postgres advisory lock keyed on the
     *    tournament: re-read status (could have closed since the
     *    FormRequest), re-check capacity, then insert. The lock serialises
     *    registration writes for THIS tournament without blocking writes
     *    to any other tournament.
     *  - Postgres partial unique indexes on (tournament_id, participant)
     *    and (tournament_id, registered_by_user_id) catch any race where
     *    two requests slip past the FormRequest before either commits.
     *    QueryException with SQLSTATE 23505 → 422 with a precise hint.
     */
    public function store(CreateRegistrationRequest $request, Tournament $tournament): JsonResponse
    {
        $data = $request->validated();

        $this->authorize('register', [
            TournamentRegistration::class,
            $tournament,
            $data['participant_type'],
            (int) $data['participant_id'],
        ]);

        if (in_array($tournament->registration_type, ['invite_only', 'signed_only'], true)) {
            abort(422, sprintf(
                'Registration type "%s" is in the schema but not yet implemented in the application layer.',
                $tournament->registration_type
            ));
        }

        try {
            $registration = DB::transaction(function () use ($request, $tournament, $data) {
                DB::statement('SELECT pg_advisory_xact_lock(?)', [$tournament->id]);

                // Re-read status under the lock — registration may have
                // closed (or been cancelled) between the FormRequest and now.
                $tournament->refresh();
                abort_unless(
                    $tournament->status === TournamentStatus::RegistrationOpen,
                    422,
                    'This tournament is not currently accepting registrations.'
                );

                if ($tournament->max_participants !== null) {
                    $approvedCount = $tournament->registrations()
                        ->where('status', RegistrationStatus::Approved->value)
                        ->count();
                    abort_if($approvedCount >= $tournament->max_participants, 422,
                        'This tournament has reached its participant cap.');
                }

                return TournamentRegistration::create([
                    ...$data,
                    'tournament_id'         => $tournament->id,
                    'registered_by_user_id' => $request->user()->id,
                    'status'                => RegistrationStatus::Pending,
                    'registered_at'         => now(),
                ]);
            });
        } catch (QueryException $e) {
            // 23505 = Postgres unique_violation. Comes from one of the two
            // partial unique indexes on tournament_registrations. The index
            // name in the error message tells us which constraint fired.
            if ($e->getCode() === '23505') {
                $msg = str_contains($e->getMessage(), 'participant_active_unique')
                    ? 'This participant already has an active registration for this tournament.'
                    : 'You already have an active registration for this tournament.';
                abort(422, $msg);
            }
            throw $e;
        }

        return (new TournamentRegistrationResource($registration))->response()->setStatusCode(201);
    }

    /**
     * tournamentRegistration.update
     * The policy admits three caller types: tournament admins, the
     * registrant, and the participant owner. Admins can change status +
     * seed; everyone else can only withdraw.
     */
    public function update(
        UpdateRegistrationRequest $request,
        Tournament $tournament,
        TournamentRegistration $registration,
    ): TournamentRegistrationResource {
        abort_unless($registration->tournament_id === $tournament->id, 404);

        $this->authorize('update', $registration);

        $user    = $request->user();
        $isAdmin = $this->isTournamentAdmin($user, $tournament);
        $data    = $request->validated();

        // Non-admin path (registrant or participant owner): only withdraw,
        // no seed. The policy gate already proved they have some claim;
        // here we just restrict the action surface.
        if (! $isAdmin) {
            if (array_key_exists('seed', $data)) {
                abort(403, 'Only a tournament admin may set seeds.');
            }
            if (($data['status'] ?? null) !== 'withdrawn') {
                abort(403, 'Non-admins may only withdraw a registration.');
            }
        }

        if (array_key_exists('status', $data)) {
            $next = RegistrationStatus::from($data['status']);
            abort_unless(
                $registration->status->canTransitionTo($next),
                422,
                sprintf('Cannot transition registration from %s to %s.',
                    $registration->status->value, $next->value)
            );
            $data['status'] = $next;
        }

        $registration->update($data);

        return new TournamentRegistrationResource($registration);
    }

    /**
     * tournamentRegistration.destroy
     * Hard-delete. Tournament admin only — owners withdraw via PATCH.
     */
    public function destroy(
        Request $request,
        Tournament $tournament,
        TournamentRegistration $registration,
    ): JsonResponse {
        abort_unless($registration->tournament_id === $tournament->id, 404);

        $this->authorize('delete', $registration);

        $registration->delete();

        return response()->json(['message' => 'Registration removed.']);
    }

    private function isTournamentAdmin($user, Tournament $tournament): bool
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
