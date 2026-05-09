<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\GameController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\OrganizationMemberController;
use App\Http\Controllers\MatchController;
use App\Http\Controllers\MatchEventController;
use App\Http\Controllers\MatchGameController;
use App\Http\Controllers\PlayerController;
use App\Http\Controllers\StageController;
use App\Http\Controllers\StageParticipantController;
use App\Http\Controllers\StageQualificationController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TeamMemberController;
use App\Http\Controllers\TournamentController;
use App\Http\Controllers\TournamentHostController;
use App\Http\Controllers\TournamentRegistrationController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// ---------------------------------------------------------------------------
// Health — public, no auth. Probes DB connectivity. Returns 503 if degraded.
// ---------------------------------------------------------------------------
Route::get('/health', HealthController::class);

// ---------------------------------------------------------------------------
// Auth
// ---------------------------------------------------------------------------
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::get('/auth/me/host-status', [AuthController::class, 'hostStatus']);
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

// ---------------------------------------------------------------------------
// Tournaments + registrations — public reads (drafts hidden), state-machine
// transitions via verb endpoints. Registrations nested under tournament.
// ---------------------------------------------------------------------------
Route::apiResource('tournaments', TournamentController::class)->only(['index', 'show']);
Route::scopeBindings()->prefix('tournaments/{tournament}')->group(function () {
    Route::get('registrations', [TournamentRegistrationController::class, 'index']);

    // Stages (public reads)
    Route::get('stages', [StageController::class, 'index']);
    Route::get('stages/{stage}', [StageController::class, 'show']);
    Route::scopeBindings()->prefix('stages/{stage}')->group(function () {
        Route::get('qualifications', [StageQualificationController::class, 'index']);
        Route::get('participants',   [StageParticipantController::class, 'index']);
        Route::get('matches',        [MatchController::class, 'index']);
    });
});

// Match reads — top-level for direct match access; bracket clients
// typically navigate from /tournaments/{t}/stages/{s}/matches.
Route::get('/matches/{match}',          [MatchController::class, 'show']);
Route::get('/matches/{match}/games',    [MatchGameController::class, 'index']);
Route::get('/matches/{match}/events',   [MatchEventController::class, 'index']);

Route::middleware('auth:sanctum')->group(function () {
    // Users — admin CRUD per the user-management plan. Index / show / create
    // gated by users.view / users.create (system_manager + superadmin).
    // Update gated by users.update (superadmin only). Delete is self OR
    // superadmin (handled in UserPolicy::delete since middleware can't
    // express the "or self" branch).
    Route::get('/users', [UserController::class, 'index']);
    Route::get('/users/{user}', [UserController::class, 'show']);
    Route::post('/users', [UserController::class, 'store']);
    Route::match(['put', 'patch'], '/users/{user}', [UserController::class, 'update']);
    Route::delete('/users/{user}', [UserController::class, 'destroy']);

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

    // Tournaments — two creation endpoints split by intent:
    //   POST /tournaments/applications  → host application, lands in DraftPendingReview
    //   POST /tournaments/drafts        → manager direct create, lands in Draft
    // Both gated by the tournaments.create permission; the controller
    // additionally rejects non-managers on /drafts.
    Route::post('/tournaments/applications', [TournamentController::class, 'applyAsHost'])
        ->middleware('permission:tournaments.create');
    Route::post('/tournaments/drafts', [TournamentController::class, 'createDraft'])
        ->middleware('permission:tournaments.create');
    Route::match(['put', 'patch'], '/tournaments/{tournament}', [TournamentController::class, 'update']);
    Route::delete('/tournaments/{tournament}', [TournamentController::class, 'destroy']);

    Route::post('/tournaments/{tournament}/approve',             [TournamentController::class, 'approve']);
    Route::post('/tournaments/{tournament}/reject',              [TournamentController::class, 'reject']);
    Route::post('/tournaments/{tournament}/open-registration',   [TournamentController::class, 'openRegistration']);
    Route::post('/tournaments/{tournament}/close-registration',  [TournamentController::class, 'closeRegistration']);
    Route::post('/tournaments/{tournament}/cancel',              [TournamentController::class, 'cancel']);
    Route::post('/tournaments/{tournament}/seed-and-build',      [TournamentController::class, 'seedAndBuild']);

    // Tournament registrations — nested. Store/update/destroy all gate via
    // controller logic (host/manager for admin paths; participant owner
    // for self-withdraw).
    Route::scopeBindings()->prefix('tournaments/{tournament}')->group(function () {
        Route::post('registrations', [TournamentRegistrationController::class, 'store']);
        Route::match(['put', 'patch'], 'registrations/{registration}', [TournamentRegistrationController::class, 'update']);
        Route::delete('registrations/{registration}', [TournamentRegistrationController::class, 'destroy']);
    });

    // Stages + qualifications + participants — all gated via per-resource
    // policies + the structure-locked-after-RegistrationClosed precondition.
    Route::scopeBindings()->prefix('tournaments/{tournament}')->group(function () {
        Route::post('stages',                  [StageController::class, 'store']);
        Route::post('stages/reorder',          [StageController::class, 'reorder']);
        Route::match(['put', 'patch'], 'stages/{stage}', [StageController::class, 'update']);
        Route::delete('stages/{stage}',        [StageController::class, 'destroy']);

        Route::scopeBindings()->prefix('stages/{stage}')->group(function () {
            Route::post('qualifications', [StageQualificationController::class, 'store']);
            Route::delete('qualifications/{qualification}', [StageQualificationController::class, 'destroy']);

            Route::post('participants', [StageParticipantController::class, 'store']);
            Route::match(['put', 'patch'], 'participants/{participant}', [StageParticipantController::class, 'update']);
            Route::delete('participants/{participant}', [StageParticipantController::class, 'destroy']);
        });
    });

    // Matches — sparse PATCH (scheduled_at, best_of), walkover verb
    // endpoint, and game-recording flow. Match creation/deletion is owned
    // by the bracket generator (commit 8) and advancement service (commit
    // 9), not user requests.
    Route::match(['put', 'patch'], '/matches/{match}', [MatchController::class, 'update']);
    Route::post('/matches/{match}/walkover',           [MatchController::class, 'walkover']);
    Route::post('/matches/{match}/games',              [MatchGameController::class, 'store']);
    Route::match(['put', 'patch'], '/match-games/{game}', [MatchGameController::class, 'update']);
    Route::delete('/match-games/{game}',               [MatchGameController::class, 'destroy']);
});
