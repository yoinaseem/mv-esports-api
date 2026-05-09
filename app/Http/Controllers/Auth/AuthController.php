<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Register a new user
     *
     * Creates a user account, issues a Sanctum personal access token, and returns the token + user payload. Enforces the hard-13 age gate per DESIGN.md §11.1 — parental consent for 13–17s is deferred. `country` defaults to `'MV'` when omitted.
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'          => ['nullable', 'string', 'max:255'],
            'display_name'  => ['nullable', 'string', 'max:255'],
            'email'         => ['required', 'email', 'unique:users,email'],
            'password'      => ['required', 'string', 'min:8', 'confirmed'],
            'date_of_birth' => ['required', 'date', 'before:'.now()->subYears(13)->toDateString()],
            'country'       => ['nullable', 'string', 'size:2'],
        ]);

        $data['country'] ??= 'MV';

        $user = User::create($data);

        return response()->json([
            'token' => $user->createToken('api')->plainTextToken,
            'user'  => new UserResource($user),
        ], 201);
    }

    /**
     * Log in
     *
     * Authenticates with email + password and returns a fresh Sanctum personal access token plus the user payload. Bad credentials produce a 422 (validation-style error) rather than a 401 so the client surfaces it on the email field.
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($request->only('email', 'password'))) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $user = Auth::user();

        return response()->json([
            'token' => $user->createToken('api')->plainTextToken,
            'user'  => new UserResource($user),
        ]);
    }

    /**
     * Log out
     *
     * Revokes the bearer token used for this request. The token is deleted from `personal_access_tokens`; subsequent requests with the same bearer will be unauthenticated.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }

    /**
     * Get the authenticated user
     *
     * Returns the caller's user payload including roles, effective permissions, and direct permissions. Used by the frontend to gate menus and probe capabilities.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => new UserResource($request->user()),
        ]);
    }

    /**
     * Get the authenticated user's tournament-host application state
     *
     * Returns the caller's `tournament_hosts` row state in a small dedicated payload — kept separate from `/me` so the user resource stays stable. Users without a host application get `has_application: false` and `status: 'none'`. Drives the frontend wizard's "apply to host" / "create tournament" gating.
     */
    public function hostStatus(Request $request): JsonResponse
    {
        $host = $request->user()->tournamentHost;

        if ($host === null) {
            return response()->json([
                'has_application' => false,
                'status'          => 'none',
                'host_id'         => null,
                'organization_id' => null,
                'display_name'    => null,
                'applied_at'      => null,
                'approved_at'     => null,
            ]);
        }

        return response()->json([
            'has_application' => true,
            'status'          => $host->status,
            'host_id'         => $host->id,
            'organization_id' => $host->organization_id,
            'display_name'    => $host->display_name,
            'applied_at'      => $host->created_at,
            'approved_at'     => $host->approved_at,
        ]);
    }
}
