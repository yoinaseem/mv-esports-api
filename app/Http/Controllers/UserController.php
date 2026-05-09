<?php

namespace App\Http\Controllers;

use App\Http\Requests\User\CreateUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\User\UserAnonymisationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserController extends Controller
{
    /**
     * List users
     *
     * Admin index. Visible to `system_manager` and `superadmin` (via `users.view`). Soft-deleted users excluded by default; superadmin can pass `?include_deleted=1` to see them too. Non-superadmin callers' use of the flag is silently ignored.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', User::class);

        $request->validate([
            'role'            => ['nullable', 'string', 'in:system_manager,superadmin'],
            'include_deleted' => ['nullable', 'boolean'],
        ]);

        $query = User::query();

        if ($request->boolean('include_deleted') && $request->user()->hasRole('superadmin')) {
            $query->withTrashed();
        }

        if ($request->filled('role')) {
            $query->whereHas('roles', fn ($q) => $q->where('name', $request->string('role')));
        }

        return UserResource::collection($query->orderBy('id')->paginate($this->perPage($request, 20)));
    }

    /**
     * Show a user
     *
     * Admin show. Soft-deleted users return 404 unless caller is superadmin.
     */
    public function show(Request $request, User $user): UserResource
    {
        $this->authorize('view', $user);

        return new UserResource($user);
    }

    /**
     * Create a user (admin)
     *
     * Manager / superadmin creates a user account on behalf of someone. Same age-gate validation as `register`. Does NOT return a token — the admin isn't logging in as the new user. Roles are not assignable here; that's a separate flow.
     */
    public function store(CreateUserRequest $request): JsonResponse
    {
        $this->authorize('create', User::class);

        $data = $request->validated();
        $data['country'] ??= 'MV';
        // Normalise email to lower-case to defend against case-variant
        // collisions on Postgres (which treats Alice@x and alice@x as
        // distinct in unique constraints).
        $data['email'] = strtolower($data['email']);

        $user = User::create($data);

        return (new UserResource($user))->response()->setStatusCode(201);
    }

    /**
     * Update a user (admin)
     *
     * Superadmin only. Patches any subset of `name, display_name, email, date_of_birth, country, password`. Email uniqueness skips the row being edited. Cannot edit `email_verified_at`, `deleted_at`, or roles via this endpoint.
     */
    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        $this->authorize('update', $user);

        $data = $request->validated();
        if (isset($data['email'])) {
            $data['email'] = strtolower($data['email']);
        }

        $user->update($data);

        return new UserResource($user);
    }

    /**
     * Delete a user (self or superadmin)
     *
     * Self-delete (route user matches authenticated user) is always available. Otherwise requires superadmin (`users.delete`). Triggers `UserAnonymisationService` — the user's row gets anonymised in place, soft-deleted, players detached, FKs nulled where nullable, and tokens revoked. Blocked with 422 if the user owns active organisations (transfer ownership first).
     */
    public function destroy(
        Request $request,
        User $user,
        UserAnonymisationService $anonymiser,
    ): JsonResponse {
        $this->authorize('delete', $user);

        try {
            $anonymiser->anonymise($user);
        } catch (\DomainException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json(['message' => 'Account deleted.']);
    }
}
