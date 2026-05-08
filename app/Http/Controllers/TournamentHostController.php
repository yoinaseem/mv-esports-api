<?php

namespace App\Http\Controllers;

use App\Http\Resources\TournamentHostResource;
use App\Models\TournamentHost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TournamentHostController extends Controller
{
    /**
     * tournamentHost.index
     * Authenticated list. Filterable by ?status=pending|approved|suspended
     * for the manager's review queue.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $hosts = TournamentHost::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderByDesc('created_at')
            ->get();

        return TournamentHostResource::collection($hosts);
    }

    /**
     * tournamentHost.show
     */
    public function show(TournamentHost $tournamentHost): TournamentHostResource
    {
        return new TournamentHostResource($tournamentHost);
    }

    /**
     * tournamentHost.store
     * Apply for host status. The user_id is forced to the caller; the row
     * starts as `pending` and waits for a system_manager to approve. Each
     * user may only have one tournament_hosts row (unique constraint).
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'display_name'    => ['required', 'string', 'max:255'],
            'bio'             => ['nullable', 'string', 'max:5000'],
        ]);

        // Unique user_id at the DB level — surface a clean 422 rather than a
        // 500 from the FK constraint.
        if (TournamentHost::where('user_id', $request->user()->id)->exists()) {
            abort(422, 'You already have a tournament host application or profile.');
        }

        $data['user_id'] = $request->user()->id;
        $data['status']  = 'pending';

        $host = TournamentHost::create($data);

        return (new TournamentHostResource($host))->response()->setStatusCode(201);
    }

    /**
     * tournamentHost.update
     * Two distinct paths:
     *  - The host themselves can patch display_name / bio / organization_id.
     *  - A system_manager / superadmin can patch status (approve / suspend).
     * Status changes by the host themselves are forbidden.
     *
     * Status transitions sync the `tournaments.create` permission: approving
     * grants it directly to the host user; any non-approved status revokes
     * the direct grant. Role-derived grants (superadmin, system_manager) are
     * untouched by revokePermissionTo, so manager users keep the capability
     * even if their own host row is suspended.
     */
    public function update(Request $request, TournamentHost $tournamentHost): TournamentHostResource
    {
        $user      = $request->user();
        $isOwner   = $tournamentHost->user_id === $user->id;
        $isManager = $user->hasAnyRole(['system_manager', 'superadmin']);

        abort_unless($isOwner || $isManager, 403);

        $rules = [
            'display_name'    => ['sometimes', 'string', 'max:255'],
            'bio'             => ['nullable', 'string', 'max:5000'],
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
        ];

        if ($isManager) {
            $rules['status'] = ['sometimes', 'string', 'in:pending,approved,suspended'];
        } elseif ($request->has('status')) {
            // Surface a clear 403 instead of silently dropping the field.
            abort(403, 'Only a system manager may change host status.');
        }

        $data = $request->validate($rules);

        $previousStatus = $tournamentHost->status;
        $newStatus      = $data['status'] ?? $previousStatus;

        if ($isManager && $newStatus === 'approved') {
            $data['approved_by_user_id'] = $user->id;
            $data['approved_at']         = now();
        }

        $tournamentHost->update($data);

        if ($isManager && $newStatus !== $previousStatus) {
            $this->syncTournamentBuilderPermission($tournamentHost, $newStatus);
        }

        return new TournamentHostResource($tournamentHost);
    }

    /**
     * tournamentHost.destroy
     * Withdraw an application (own only) or remove (system_manager). Revokes
     * the directly-granted tournaments.create permission so a withdrawn host
     * doesn't keep tournament-builder access.
     */
    public function destroy(Request $request, TournamentHost $tournamentHost): JsonResponse
    {
        $user      = $request->user();
        $isOwner   = $tournamentHost->user_id === $user->id;
        $isManager = $user->hasAnyRole(['system_manager', 'superadmin']);

        abort_unless($isOwner || $isManager, 403);

        // Revoke the direct grant before the row goes away. Role-derived
        // grants are untouched (Spatie's revokePermissionTo only removes
        // direct permissions), so superadmin/system_manager keep the
        // capability through their role.
        $tournamentHost->user?->revokePermissionTo('tournaments.create');

        $tournamentHost->delete();

        return response()->json(['message' => 'Tournament host record removed.']);
    }

    /**
     * Grant or revoke `tournaments.create` for the host user based on their
     * new status. Idempotent: givePermissionTo / revokePermissionTo are safe
     * to call when the user already has / lacks the permission directly.
     */
    private function syncTournamentBuilderPermission(TournamentHost $host, string $status): void
    {
        $hostUser = $host->user;
        if (! $hostUser) {
            return;
        }

        if ($status === 'approved') {
            $hostUser->givePermissionTo('tournaments.create');
        } else {
            // Suspended / pending / anything else → revoke the direct grant.
            $hostUser->revokePermissionTo('tournaments.create');
        }
    }
}
