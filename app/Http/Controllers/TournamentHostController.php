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
     * List tournament hosts
     *
     * Public list. Filterable by `?status=pending|approved|suspended` for the manager review queue. Sorted newest-application first.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $hosts = TournamentHost::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderByDesc('created_at')
            ->paginate($this->perPage($request, 20));

        return TournamentHostResource::collection($hosts);
    }

    /**
     * Show a tournament host
     *
     * Public read for a single tournament-host record.
     */
    public function show(TournamentHost $tournamentHost): TournamentHostResource
    {
        return new TournamentHostResource($tournamentHost);
    }

    /**
     * Apply for tournament host status
     *
     * Authenticated entry point. The `user_id` is forced to the caller; the row starts as `pending` and waits for a system_manager to approve. Each user may only have one tournament_hosts row — a duplicate application returns 422.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'display_name'    => ['required', 'string', 'max:255'],
            'bio'             => ['nullable', 'string', 'max:5000'],
        ]);

        if (TournamentHost::where('user_id', $request->user()->id)->exists()) {
            abort(422, 'You already have a tournament host application or profile.');
        }

        $data['user_id'] = $request->user()->id;
        $data['status']  = 'pending';

        $host = TournamentHost::create($data);

        return (new TournamentHostResource($host))->response()->setStatusCode(201);
    }

    /**
     * Update a tournament host
     *
     * Two distinct caller paths. The host themselves may patch `display_name`, `bio`, `organization_id`. A `system_manager` / `superadmin` may additionally patch `status` (`pending` → `approved` → `suspended`); status changes by the host themselves are 403. Approving auto-stamps `approved_by_user_id` and `approved_at`. Status transitions sync the `tournaments.create` permission: approving grants it directly; any non-approved status revokes the direct grant. Role-derived grants on managers are untouched.
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
     * Withdraw / remove a tournament host
     *
     * Owner withdraws their own application; a manager can remove any host record. Revokes the directly-granted `tournaments.create` permission so a withdrawn host doesn't keep tournament-builder access. Manager role-derived grants are untouched.
     */
    public function destroy(Request $request, TournamentHost $tournamentHost): JsonResponse
    {
        $user      = $request->user();
        $isOwner   = $tournamentHost->user_id === $user->id;
        $isManager = $user->hasAnyRole(['system_manager', 'superadmin']);

        abort_unless($isOwner || $isManager, 403);

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
            $hostUser->revokePermissionTo('tournaments.create');
        }
    }
}
