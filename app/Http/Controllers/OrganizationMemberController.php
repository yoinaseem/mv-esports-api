<?php

namespace App\Http\Controllers;

use App\Http\Resources\OrganizationMemberResource;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrganizationMemberController extends Controller
{
    /**
     * List members of an organisation. Public read; the org's roster is not
     * sensitive at MVP. Includes left members; pass ?active=1 to filter.
     */
    public function index(Request $request, Organization $organization): AnonymousResourceCollection
    {
        $members = $organization->members()
            ->when($request->boolean('active'), fn ($q) => $q->whereNull('left_at'))
            ->orderBy('joined_at')
            ->get();

        return OrganizationMemberResource::collection($members);
    }

    public function store(Request $request, Organization $organization): JsonResponse
    {
        $this->authorizeOwnerOrAdmin($request->user(), $organization);

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'role'    => ['required', 'string', 'in:owner,staff,member'],
        ]);

        $member = OrganizationMember::create([
            'organization_id' => $organization->id,
            'user_id'         => $data['user_id'],
            'role'            => $data['role'],
            'joined_at'       => now(),
        ]);

        return (new OrganizationMemberResource($member))->response()->setStatusCode(201);
    }

    public function update(Request $request, Organization $organization, OrganizationMember $member): OrganizationMemberResource
    {
        $this->authorizeOwnerOrAdmin($request->user(), $organization);
        abort_unless($member->organization_id === $organization->id, 404);

        $data = $request->validate([
            'role'    => ['sometimes', 'string', 'in:owner,staff,member'],
            'left_at' => ['sometimes', 'nullable', 'date'],
        ]);

        $member->update($data);

        return new OrganizationMemberResource($member);
    }

    public function destroy(Request $request, Organization $organization, OrganizationMember $member): JsonResponse
    {
        $this->authorizeOwnerOrAdmin($request->user(), $organization);
        abort_unless($member->organization_id === $organization->id, 404);

        $member->delete();

        return response()->json(['message' => 'Member removed.']);
    }

    private function authorizeOwnerOrAdmin(User $user, Organization $organization): void
    {
        $isOwner      = $user->id === $organization->owner_user_id;
        $isSuperadmin = $user->hasRole('superadmin');

        abort_unless($isOwner || $isSuperadmin, 403, 'Only the organisation owner or a superadmin may manage members.');
    }
}
