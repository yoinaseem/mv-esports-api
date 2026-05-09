<?php

namespace App\Http\Controllers;

use App\Enums\RegistrationStatus;
use App\Enums\TournamentStatus;
use App\Http\Requests\Tournament\CreateTournamentRequest;
use App\Http\Requests\Tournament\UpdateTournamentRequest;
use App\Http\Resources\TournamentResource;
use App\Models\Tournament;
use App\Services\Bracket\SeedAndBuildService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class TournamentController extends Controller
{
    /**
     * List tournaments
     *
     * Public list, sorted newest-start-date first. Filterable by `?game_id`, `?status`, `?host_id`, `?organization_id`. Drafts (with or without review) are hidden from anonymous callers by default; managers can see them by adding `?include_drafts=1`.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Tournament::query()
            ->when($request->filled('game_id'), fn ($q) => $q->where('game_id', $request->integer('game_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('host_id'), fn ($q) => $q->where('host_id', $request->integer('host_id')))
            ->when($request->filled('organization_id'), fn ($q) => $q->where('organization_id', $request->integer('organization_id')));

        $includeDrafts = $request->boolean('include_drafts')
            && $request->user()
            && $request->user()->hasAnyRole(['system_manager', 'superadmin']);

        if (! $includeDrafts) {
            $query->whereNotIn('status', [
                TournamentStatus::DraftPendingReview->value,
                TournamentStatus::Draft->value,
            ]);
        }

        return TournamentResource::collection(
            $query->orderByDesc('start_date')->paginate($this->perPage($request, 20))
        );
    }

    /**
     * Show a tournament
     *
     * Authorized via `TournamentPolicy::view` — handles both anonymous public access and creator/manager draft access. Returns 404 (not 403) on deny so the existence of a draft isn't leaked to outsiders.
     */
    public function show(Request $request, Tournament $tournament): TournamentResource
    {
        abort_unless(Gate::allows('view', $tournament), 404);

        return new TournamentResource($tournament);
    }

    /**
     * Apply to host a tournament
     *
     * Host application path. Always lands in `DraftPendingReview` — a manager must subsequently approve. The caller must be either a system manager (allowed for unification) or have an approved `tournament_hosts` row. A user with `tournaments.create` granted directly (admin override, no host row) is rejected — the "application" framing requires actual host status.
     */
    public function applyAsHost(CreateTournamentRequest $request): JsonResponse
    {
        $user = $request->user();

        $isManager      = $user->hasAnyRole(['system_manager', 'superadmin']);
        $hostRow        = $user->tournamentHost;
        $isApprovedHost = $hostRow && $hostRow->status === 'approved';

        abort_unless(
            $isManager || $isApprovedHost,
            403,
            'Only approved tournament hosts (or system managers) may apply to host tournaments.'
        );

        $tournament = Tournament::create([
            ...$request->validated(),
            'created_by_user_id' => $user->id,
            'host_id'            => $isApprovedHost ? $hostRow->id : null,
            'status'             => TournamentStatus::DraftPendingReview,
        ]);

        return (new TournamentResource($tournament))->response()->setStatusCode(201);
    }

    /**
     * Create a draft tournament directly
     *
     * Manager-only entry point. Lands in `Draft` directly — no review needed because managers are the reviewers. Non-managers are rejected with a 403 redirecting them to `POST /api/tournaments/applications`.
     */
    public function createDraft(CreateTournamentRequest $request): JsonResponse
    {
        $user = $request->user();

        abort_unless(
            $user->hasAnyRole(['system_manager', 'superadmin']),
            403,
            'Only system managers may create drafts directly. Hosts should use POST /api/tournaments/applications.'
        );

        $tournament = Tournament::create([
            ...$request->validated(),
            'created_by_user_id' => $user->id,
            'host_id'            => null,
            'status'             => TournamentStatus::Draft,
        ]);

        return (new TournamentResource($tournament))->response()->setStatusCode(201);
    }

    /**
     * Update a tournament
     *
     * Patch non-status fields (description, dates, stream URL, etc.). Status transitions are not accepted here — submitting `status` returns 403 with a hint pointing at the dedicated verb endpoints (`approve`, `reject`, `open-registration`, `close-registration`, `cancel`).
     */
    public function update(UpdateTournamentRequest $request, Tournament $tournament): TournamentResource
    {
        $this->authorize('update', $tournament);

        if ($request->has('status')) {
            abort(403, 'Status transitions go through dedicated endpoints (approve, reject, open-registration, close-registration, cancel).');
        }

        $tournament->update($request->validated());

        return new TournamentResource($tournament);
    }

    /**
     * Archive a tournament
     *
     * Soft-delete. Allowed only when status is `DraftPendingReview` or `Cancelled` — live tournaments (registration_open, in_progress, completed) can't be archived. The creator or a superadmin may archive.
     */
    public function destroy(Request $request, Tournament $tournament): JsonResponse
    {
        $this->authorize('delete', $tournament);

        $allowedForDelete = [TournamentStatus::DraftPendingReview, TournamentStatus::Cancelled];
        abort_unless(
            in_array($tournament->status, $allowedForDelete, true),
            422,
            'Only draft-pending-review or cancelled tournaments may be archived.'
        );

        $tournament->delete();

        return response()->json(['message' => 'Tournament archived.']);
    }

    // -----------------------------------------------------------------------
    // State-transition verb endpoints
    // -----------------------------------------------------------------------

    /**
     * Approve a tournament
     *
     * Manager / superadmin only. Transitions `DraftPendingReview` → `Draft` and stamps `approved_by_user_id` + `approved_at`. Any other starting status returns a 422 from the state-machine guard.
     */
    public function approve(Request $request, Tournament $tournament): TournamentResource
    {
        $this->authorize('approve', $tournament);
        $this->transition($tournament, TournamentStatus::Draft, [
            'approved_by_user_id' => $request->user()->id,
            'approved_at'         => now(),
        ]);

        return new TournamentResource($tournament);
    }

    /**
     * Reject a tournament application
     *
     * Manager / superadmin only. Transitions `DraftPendingReview` → `Cancelled`. Any other starting status returns 422.
     */
    public function reject(Request $request, Tournament $tournament): TournamentResource
    {
        $this->authorize('reject', $tournament);
        abort_unless(
            $tournament->status === TournamentStatus::DraftPendingReview,
            422,
            'Only tournaments awaiting review may be rejected.'
        );
        $this->transition($tournament, TournamentStatus::Cancelled);

        return new TournamentResource($tournament);
    }

    /**
     * Open registration
     *
     * Host or manager. Transitions `Draft` → `RegistrationOpen`. Requires the tournament to have at least one stage configured — this is the natural gate for "no half-built tournaments going live." After this, participants can submit registrations via `POST /api/tournaments/{id}/registrations`.
     */
    public function openRegistration(Request $request, Tournament $tournament): TournamentResource
    {
        $this->authorize('openRegistration', $tournament);

        // State-machine guard first — wrong-state rejection takes precedence
        // over the missing-stages precondition (a tournament in
        // DraftPendingReview has the same "no stages" property as a fresh
        // Draft, but the right error to surface is "approve me first").
        abort_unless(
            $tournament->status->canTransitionTo(TournamentStatus::RegistrationOpen),
            422,
            sprintf('Cannot transition from %s to registration_open.', $tournament->status->value)
        );

        abort_if(
            $tournament->stages()->count() === 0,
            422,
            'Cannot open registration: the tournament has no stages defined.'
        );

        $this->transition($tournament, TournamentStatus::RegistrationOpen);

        return new TournamentResource($tournament);
    }

    /**
     * Close registration
     *
     * Host or manager. Transitions `RegistrationOpen` → `RegistrationClosed`. Wraps two operations in a `DB::transaction`: the status change AND a mass auto-reject of all `pending` registrations so closing doesn't leave stale pending rows around. Approved registrations are untouched. The mass update explicitly bumps `updated_at` because Laravel's query-builder `update()` doesn't auto-touch timestamps.
     */
    public function closeRegistration(Request $request, Tournament $tournament): TournamentResource
    {
        $this->authorize('closeRegistration', $tournament);

        DB::transaction(function () use ($tournament) {
            $this->transition($tournament, TournamentStatus::RegistrationClosed);
            $tournament->registrations()
                ->where('status', RegistrationStatus::Pending->value)
                ->update([
                    'status'     => RegistrationStatus::Rejected->value,
                    'updated_at' => now(),
                ]);
        });

        return new TournamentResource($tournament);
    }

    /**
     * Cancel a tournament
     *
     * Host or manager. Transitions any non-terminal status (`DraftPendingReview`, `Draft`, `RegistrationOpen`, `RegistrationClosed`, `InProgress`) to `Cancelled`. Once cancelled, the tournament can be soft-deleted via `DELETE /api/tournaments/{id}`.
     */
    public function cancel(Request $request, Tournament $tournament): TournamentResource
    {
        $this->authorize('cancel', $tournament);
        $this->transition($tournament, TournamentStatus::Cancelled);

        return new TournamentResource($tournament);
    }

    /**
     * Seed the entry stage and build the bracket
     *
     * Host or manager. Run from `RegistrationClosed`: copies approved registrations into the entry stage's `stage_participants` (where the qualification rule is `all` from a null source), invokes the format-specific bracket generator (single_elim / double_elim / round_robin), transitions each built stage from `Pending → InProgress` and the tournament from `RegistrationClosed → InProgress`. Atomic — any precondition failure or generator error rolls back. Re-running on a tournament that already has matches is rejected (422); regeneration isn't supported.
     */
    public function seedAndBuild(
        Request $request,
        Tournament $tournament,
        SeedAndBuildService $svc,
    ): TournamentResource {
        $this->authorize('seedAndBuild', $tournament);

        try {
            $summary = $svc->execute($tournament);
        } catch (\DomainException $e) {
            abort(422, $e->getMessage());
        }

        return (new TournamentResource($tournament->fresh()))
            ->additional(['bracket_summary' => $summary]);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function transition(Tournament $tournament, TournamentStatus $next, array $extra = []): void
    {
        abort_unless(
            $tournament->status->canTransitionTo($next),
            422,
            sprintf('Cannot transition from %s to %s.', $tournament->status->value, $next->value)
        );

        $tournament->update(array_merge($extra, ['status' => $next]));
    }
}
