<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\GameController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\OrganizationMemberController;
use App\Http\Controllers\PlayerController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TeamMemberController;
use App\Http\Controllers\TournamentHostController;
use Illuminate\Support\Facades\Route;

// ---------------------------------------------------------------------------
// Auth
// ---------------------------------------------------------------------------
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
});

// ---------------------------------------------------------------------------
// Catalog — public reads, write-gated mutations
// ---------------------------------------------------------------------------
Route::apiResource('games', GameController::class)->only(['index', 'show']);
Route::apiResource('organizations', OrganizationController::class)->only(['index', 'show']);
Route::scopeBindings()->prefix('organizations/{organization}')->group(function () {
    Route::get('members', [OrganizationMemberController::class, 'index']);
});

// ---------------------------------------------------------------------------
// Identity capabilities — players (per-user-per-game profiles) and
// tournament_hosts (capability application/approval).
// Public reads; auth required for any mutation.
// ---------------------------------------------------------------------------
Route::apiResource('players', PlayerController::class)->only(['index', 'show']);
Route::apiResource('tournament-hosts', TournamentHostController::class)
    ->parameters(['tournament-hosts' => 'tournamentHost'])
    ->only(['index', 'show']);

// ---------------------------------------------------------------------------
// Teams + team members — public roster reads, write-gated mutations
// ---------------------------------------------------------------------------
Route::apiResource('teams', TeamController::class)->only(['index', 'show']);
Route::scopeBindings()->prefix('teams/{team}')->group(function () {
    Route::get('members', [TeamMemberController::class, 'index']);
});

Route::middleware('auth:sanctum')->group(function () {
    // Games — system-level catalog. Gated by games.manage permission;
    // RolesAndPermissionsSeeder grants it to superadmin + system_manager.
    Route::post('/games', [GameController::class, 'store'])
        ->middleware('permission:games.manage');
    Route::match(['put', 'patch'], '/games/{game}', [GameController::class, 'update'])
        ->middleware('permission:games.manage');
    Route::delete('/games/{game}', [GameController::class, 'destroy'])
        ->middleware('permission:games.manage');

    // Organizations — any auth user can create; update/delete gated by
    // ownership (or superadmin override) inside the controller.
    Route::post('/organizations', [OrganizationController::class, 'store']);
    Route::match(['put', 'patch'], '/organizations/{organization}', [OrganizationController::class, 'update']);
    Route::delete('/organizations/{organization}', [OrganizationController::class, 'destroy']);

    // Org members — owner/superadmin only (controller-level check).
    Route::scopeBindings()->prefix('organizations/{organization}')->group(function () {
        Route::post('members', [OrganizationMemberController::class, 'store']);
        Route::match(['put', 'patch'], 'members/{member}', [OrganizationMemberController::class, 'update']);
        Route::delete('members/{member}', [OrganizationMemberController::class, 'destroy']);
    });

    // Players — owner-gated mutations (controller-level: player.user_id == auth user).
    Route::post('/players', [PlayerController::class, 'store']);
    Route::match(['put', 'patch'], '/players/{player}', [PlayerController::class, 'update']);
    Route::delete('/players/{player}', [PlayerController::class, 'destroy']);

    // Tournament hosts — apply (any auth user, one per user); update / destroy
    // is owner-OR-manager (controller-level). Status transitions are
    // manager-only and enforced inside the update action.
    Route::post('/tournament-hosts', [TournamentHostController::class, 'store']);
    Route::match(['put', 'patch'], '/tournament-hosts/{tournamentHost}', [TournamentHostController::class, 'update']);
    Route::delete('/tournament-hosts/{tournamentHost}', [TournamentHostController::class, 'destroy']);

    // Teams — creator/captain/superadmin manage at the controller layer.
    Route::post('/teams', [TeamController::class, 'store']);
    Route::match(['put', 'patch'], '/teams/{team}', [TeamController::class, 'update']);
    Route::delete('/teams/{team}', [TeamController::class, 'destroy']);

    // Team members — nested under teams; admin-gated via controller. The
    // PATCH endpoint also accepts a "self-leave" path for the player whose
    // membership it is.
    Route::scopeBindings()->prefix('teams/{team}')->group(function () {
        Route::post('members', [TeamMemberController::class, 'store']);
        Route::match(['put', 'patch'], 'members/{member}', [TeamMemberController::class, 'update']);
        Route::delete('members/{member}', [TeamMemberController::class, 'destroy']);
    });
});
