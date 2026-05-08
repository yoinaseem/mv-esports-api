<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\GameController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\OrganizationMemberController;
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
});
