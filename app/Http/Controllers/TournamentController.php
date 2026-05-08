<?php

namespace App\Http\Controllers;

use App\Enums\RegistrationStatus;
use App\Enums\TournamentStatus;
use App\Http\Requests\Tournament\CreateTournamentRequest;
use App\Http\Requests\Tournament\UpdateTournamentRequest;
use App\Http\Resources\TournamentResource;
use App\Models\Tournament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class TournamentController extends Controller
{
    /**
     * tournament.index
     * Public list. Drafts (with or without review) are hidden from
     * anonymous callers; managers see them via ?include_drafts=1.
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
            $query->orderByDesc('start_date')->get()
        );
    }

    /**
     * tournament.show
     * Authorized via TournamentPolicy::view (handles both anonymous public
     * access and creator/manager draft access). Returns 404 (not 403) on
     * deny so the existence of a draft isn't leaked to outsiders.
     */
    public function show(Request $request, Tournament $tournament): TournamentResource
    {
        abort_unless(Gate::allows('view', $tournament), 404);

        return new TournamentResource($tournament);
    }

    /**
     * tournament.applyAsHost  (POST /api/tournaments/applications)
     * Host application path. Always lands in DraftPendingReview.
     *
     * Caller must either be a system manager (allowed for unification —
     * managers may pick this path even though /drafts is faster), OR have
     * an approved tournament_hosts row. A user with tournaments.create
     * granted directly (admin override, no host row) is rejected — the
     * "application" framing requires actual host status.
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
     * tournament.createDraft  (POST /api/tournaments/drafts)
     * Manager-only entry point. Lands in Draft directly (no review needed).
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
     * tournament.update
     * Patch non-status fields. Status transitions go through dedicated
     * verb endpoints.
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
     * tournament.destroy
     * Soft-delete. Allowed only when status is DraftPendingReview or
     * Cancelled — live tournaments can't be archived.
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

    public function approve(Request $request, Tournament $tournament): TournamentResource
    {
        $this->authorize('approve', $tournament);
        $this->transition($tournament, TournamentStatus::Draft, [
            'approved_by_user_id' => $request->user()->id,
            'approved_at'         => now(),
        ]);

        return new TournamentResource($tournament);
    }

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

    public function openRegistration(Request $request, Tournament $tournament): TournamentResource
    {
        $this->authorize('openRegistration', $tournament);
        $this->transition($tournament, TournamentStatus::RegistrationOpen);

        return new TournamentResource($tournament);
    }

    /**
     * Closing registration auto-rejects all pending registrations in a
     * single transaction so a partial failure doesn't leave the tournament
     * closed with stale pending rows. The mass update explicitly bumps
     * updated_at — Laravel's query-builder update() doesn't auto-touch
     * timestamps, so callers filtering by recent updates would otherwise
     * miss the auto-rejected rows.
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

    public function cancel(Request $request, Tournament $tournament): TournamentResource
    {
        $this->authorize('cancel', $tournament);
        $this->transition($tournament, TournamentStatus::Cancelled);

        return new TournamentResource($tournament);
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
