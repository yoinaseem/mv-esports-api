<?php

namespace App\Http\Controllers;

use App\Http\Resources\OrganizationResource;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrganizationController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return OrganizationResource::collection(
            Organization::query()->orderBy('name')->get()
        );
    }

    public function show(Organization $organization): OrganizationResource
    {
        return new OrganizationResource($organization);
    }

    /**
     * organization.store
     * Any authenticated user can register an organisation. The creator
     * becomes the owner; further owner changes are out of scope for now
     * (no transfer-ownership endpoint).
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'slug'        => ['required', 'string', 'max:255', 'unique:organizations,slug'],
            'logo_url'    => ['nullable', 'url', 'max:2048'],
            'description' => ['nullable', 'string', 'max:5000'],
        ]);

        $data['owner_user_id'] = $request->user()->id;

        $organization = Organization::create($data);

        return (new OrganizationResource($organization))->response()->setStatusCode(201);
    }

    public function update(Request $request, Organization $organization): OrganizationResource
    {
        $this->authorizeOwnerOrAdmin($request->user(), $organization);

        $data = $request->validate([
            'name'        => ['sometimes', 'string', 'max:255'],
            'slug'        => ['sometimes', 'string', 'max:255', 'unique:organizations,slug,'.$organization->id],
            'logo_url'    => ['nullable', 'url', 'max:2048'],
            'description' => ['nullable', 'string', 'max:5000'],
        ]);

        $organization->update($data);

        return new OrganizationResource($organization);
    }

    public function destroy(Request $request, Organization $organization): JsonResponse
    {
        $this->authorizeOwnerOrAdmin($request->user(), $organization);

        $organization->delete();

        return response()->json(['message' => 'Organization archived.']);
    }

    /**
     * Org-owner OR superadmin can mutate. system_manager intentionally does
     * NOT auto-manage org-owned resources (DESIGN.md §5: orgs are
     * community-created and gated by ownership).
     */
    private function authorizeOwnerOrAdmin(User $user, Organization $organization): void
    {
        $isOwner      = $user->id === $organization->owner_user_id;
        $isSuperadmin = $user->hasRole('superadmin');

        abort_unless($isOwner || $isSuperadmin, 403, 'Only the organisation owner or a superadmin may modify this organisation.');
    }
}
