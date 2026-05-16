<?php

namespace App\Http\Controllers;

use App\Enums\RegistrationStatus;
use App\Enums\TournamentStatus;
use App\Http\Requests\TournamentRegistration\BulkSeedRegistrationsRequest;
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
     * List registrations for a tournament
     *
     * Public list scoped to the parent tournament. Optional `?status=pending|approved|rejected|withdrawn` filter. Sorted by `registered_at` (oldest first).
     */
    public function index(Request $request, Tournament $tournament): AnonymousResourceCollection
    {
        $registrations = $tournament->registrations()
            ->with(['participant' => fn ($q) => $q->morphWith([
                \App\Models\Player::class => ['user'],
            ])])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderBy('registered_at')
            ->paginate($this->perPage($request, 20));

        return TournamentRegistrationResource::collection($registrations);
    }

    /**
     * Register a participant
     *
     * Submits a participant (team or player) for a tournament. Requires the tournament to be in `RegistrationOpen` and the caller to own the participant (player.user_id, team creator, or active captain). Each user may register at most one participant per tournament; each participant may have at most one active registration. The endpoint is race-safe: wraps in a `DB::transaction` with a Postgres advisory lock keyed on the tournament, re-reads status under the lock, and falls back to two partial unique indexes (`tournament_registrations_participant_active_unique`, `tournament_registrations_user_active_unique`) for any concurrent-write window the application checks miss. Unique-violation errors translate to friendly 422s. Registration types `invite_only` and `signed_only` are schema-only at MVP and return 422.
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

                // Window enforcement: the host's planned registration_opens_at
                // / registration_closes_at bounds. Status RegistrationOpen
                // says the host has manually opened registration; the window
                // says when registration is allowed to happen. If the host
                // clicked "open registration" before registration_opens_at,
                // they need to PATCH the date or wait. If we're past
                // registration_closes_at, they need to extend or accept that
                // registration is over.
                $now = now();
                abort_if(
                    $now->lt($tournament->registration_opens_at)
                        || $now->gt($tournament->registration_closes_at),
                    422,
                    'Registration is not currently within its open window.'
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
     * Update a registration
     *
     * The policy admits three caller types: tournament admins (host / creator / system role), the registrant (whoever clicked register), and the participant owner (player.user_id, team creator, or active captain). Admins may change `status` (subject to `RegistrationStatus::canTransitionTo`) and assign `seed`. Non-admins may only withdraw and may not set seed.
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

        // Enforce max_participants cap on transitions INTO approved.
        // Without this, the host can over-approve past the cap (the cap
        // check on `store` only blocks NEW registrations once approved
        // count >= max — it doesn't gate the pending → approved
        // transition). Wrap in a transaction + advisory lock so two
        // simultaneous approval clicks can't both squeak past the cap.
        $approvingNow = isset($data['status'])
            && $data['status'] === RegistrationStatus::Approved
            && $registration->status !== RegistrationStatus::Approved;

        if ($approvingNow && $tournament->max_participants !== null) {
            DB::transaction(function () use ($tournament, $registration, $data) {
                DB::statement('SELECT pg_advisory_xact_lock(?)', [$tournament->id]);

                $approvedCount = $tournament->registrations()
                    ->where('status', RegistrationStatus::Approved->value)
                    ->count();
                abort_if($approvedCount >= $tournament->max_participants, 422,
                    'This tournament has reached its participant cap.');

                $registration->update($data);
            });
        } else {
            $registration->update($data);
        }

        return new TournamentRegistrationResource($registration);
    }

    /**
     * Bulk-assign seeds to all approved registrations
     *
     * Tournament admin only. Accepts a full-set assignment of seeds to the tournament's approved registrations — `assignments` must include every approved registration exactly once, and the seed values must form a contiguous `1..N` sequence (no gaps, no duplicates). Atomic: all seeds update in one transaction, or none do.
     *
     * Available in `RegistrationOpen` and `RegistrationClosed`. Seeds are read by the bracket generator at `seed-and-build`; once that runs, seeds are baked into `stage_participants` and further changes here have no effect.
     *
     * Use cases: the host arranges a drag-and-drop seeding UI on the frontend, posts the final ordering as one bulk update. Random shuffles are client-side (frontend picks the random ordering, posts it).
     */
    public function bulkSeed(
        BulkSeedRegistrationsRequest $request,
        Tournament $tournament,
    ): AnonymousResourceCollection {
        abort_unless($this->isTournamentAdmin($request->user(), $tournament), 403);

        abort_unless(
            in_array($tournament->status, [
                TournamentStatus::RegistrationOpen,
                TournamentStatus::RegistrationClosed,
            ], true),
            422,
            sprintf('Bulk seed only available in registration_open or registration_closed; tournament is %s.', $tournament->status->value),
        );

        $assignments = collect($request->validated()['assignments']);

        // Per-input uniqueness.
        if ($assignments->pluck('registration_id')->duplicates()->isNotEmpty()) {
            abort(422, 'Duplicate registration_id in assignments.');
        }
        if ($assignments->pluck('seed')->duplicates()->isNotEmpty()) {
            abort(422, 'Duplicate seed value in assignments.');
        }

        // Seeds form 1..N.
        $seeds   = $assignments->pluck('seed')->sort()->values();
        $expected = collect(range(1, $assignments->count()));
        if ($seeds->toArray() !== $expected->toArray()) {
            abort(422, sprintf(
                'Seeds must form a contiguous 1..%d sequence; got [%s].',
                $assignments->count(),
                $seeds->implode(', '),
            ));
        }

        // Full-set: every approved registration covered exactly once.
        $approved = $tournament->registrations()
            ->where('status', RegistrationStatus::Approved->value)
            ->get()
            ->keyBy('id');

        if ($assignments->count() !== $approved->count()) {
            abort(422, sprintf(
                'Full-set assignment required: %d approved registrations exist; got %d assignments.',
                $approved->count(),
                $assignments->count(),
            ));
        }

        foreach ($assignments as $a) {
            if (! $approved->has($a['registration_id'])) {
                abort(422, sprintf(
                    'Registration %d is not an approved registration of this tournament.',
                    $a['registration_id'],
                ));
            }
        }

        DB::transaction(function () use ($assignments, $approved) {
            foreach ($assignments as $a) {
                $approved->get($a['registration_id'])->update(['seed' => $a['seed']]);
            }
        });

        $refreshed = $tournament->registrations()
            ->where('status', RegistrationStatus::Approved->value)
            ->with(['participant' => fn ($q) => $q->morphWith([
                \App\Models\Player::class => ['user'],
            ])])
            ->orderBy('seed')
            ->get();

        return TournamentRegistrationResource::collection($refreshed);
    }

    /**
     * Hard-delete a registration
     *
     * Tournament admin only — participants/owners withdraw via PATCH with `status=withdrawn` (which preserves the row for history). This endpoint hard-removes the row and is intended for accidentally-submitted registrations where keeping history would be misleading.
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
