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
    /**
     * List organisations
     *
     * Public list of all organisations, sorted by name.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return OrganizationResource::collection(
            Organization::query()->orderBy('name')->paginate($this->perPage($request, 20))
        );
    }

    /**
     * Show an organisation
     *
     * Public read for a single organisation by id.
     */
    public function show(Organization $organization): OrganizationResource
    {
        $organization->load('owner');

        return new OrganizationResource($organization);
    }

    /**
     * Create an organisation
     *
     * Any authenticated user can register an organisation. The creator is recorded as `owner_user_id` and gains exclusive update/delete rights. Further owner changes are out of scope at MVP — there is no transfer-ownership endpoint.
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

    /**
     * Update an organisation
     *
     * Owner-only mutation; superadmin overrides for moderation cases. system_manager intentionally does NOT auto-manage org-owned resources (DESIGN.md §5: orgs are community-created and gated by ownership).
     */
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

    /**
     * Archive an organisation
     *
     * Soft-delete. The owner or a superadmin may archive; the row stays recoverable via `withTrashed()`. Affiliated teams have their `organization_id` set to null (per FK) but otherwise survive.
     */
    public function destroy(Request $request, Organization $organization): JsonResponse
    {
        $this->authorizeOwnerOrAdmin($request->user(), $organization);

        $organization->delete();

        return response()->json(['message' => 'Organization archived.']);
    }

    private function authorizeOwnerOrAdmin(User $user, Organization $organization): void
    {
        $isOwner      = $user->id === $organization->owner_user_id;
        $isSuperadmin = $user->hasRole('superadmin');

        abort_unless($isOwner || $isSuperadmin, 403, 'Only the organisation owner or a superadmin may modify this organisation.');
    }
}
