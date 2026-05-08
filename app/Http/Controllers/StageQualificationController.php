<?php

namespace App\Http\Controllers;

use App\Http\Requests\StageQualification\CreateStageQualificationRequest;
use App\Http\Resources\StageQualificationResource;
use App\Models\Stage;
use App\Models\StageQualification;
use App\Models\Tournament;
use App\Policies\StagePolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StageQualificationController extends Controller
{
    /**
     * List qualification rules for a stage
     *
     * Public list of incoming qualification rules for the given stage — i.e. "what feeds this stage." Sorted by id (creation order).
     */
    public function index(Request $request, Tournament $tournament, Stage $stage): AnonymousResourceCollection
    {
        abort_unless($stage->tournament_id === $tournament->id, 404);

        return StageQualificationResource::collection($stage->incomingQualifications);
    }

    /**
     * Add a qualification rule
     *
     * Adds a rule that feeds participants into this stage. Tournament admin only; structure locked once registration_closed. Validates that the proposed rule wouldn't create a cycle in the dependency graph and that the source stage (if any) belongs to the same tournament.
     */
    public function store(CreateStageQualificationRequest $request, Tournament $tournament, Stage $stage): JsonResponse
    {
        abort_unless($stage->tournament_id === $tournament->id, 404);
        $this->authorize('create', [StageQualification::class, $stage]);

        abort_unless(
            StagePolicy::structureUnlocked($tournament),
            422,
            'Stage structure is locked once registration has closed.'
        );

        $qualification = StageQualification::create([
            ...$request->validated(),
            'target_stage_id' => $stage->id,
        ]);

        return (new StageQualificationResource($qualification))->response()->setStatusCode(201);
    }

    /**
     * Remove a qualification rule
     *
     * Tournament admin only. Removes the rule from the dependency graph; structure must be unlocked. Qualification rules are immutable — to change a rule, delete and recreate.
     */
    public function destroy(
        Request $request,
        Tournament $tournament,
        Stage $stage,
        StageQualification $qualification,
    ): JsonResponse {
        abort_unless($stage->tournament_id === $tournament->id, 404);
        abort_unless($qualification->target_stage_id === $stage->id, 404);
        $this->authorize('delete', $qualification);

        abort_unless(
            StagePolicy::structureUnlocked($tournament),
            422,
            'Stage structure is locked once registration has closed.'
        );

        $qualification->delete();

        return response()->json(['message' => 'Qualification rule removed.']);
    }
}
