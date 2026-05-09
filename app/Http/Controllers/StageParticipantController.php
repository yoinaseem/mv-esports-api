<?php

namespace App\Http\Controllers;

use App\Http\Requests\StageParticipant\CreateStageParticipantRequest;
use App\Http\Requests\StageParticipant\UpdateStageParticipantRequest;
use App\Http\Resources\StageParticipantResource;
use App\Models\Stage;
use App\Models\StageParticipant;
use App\Models\Tournament;
use App\Policies\StagePolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StageParticipantController extends Controller
{
    /**
     * List participants in a stage
     *
     * Public list of participants slotted into this stage. Sorted by `seed` (ascending). Optional `?status=active|eliminated|withdrawn` filter and `?group_number=N` for grouped formats.
     */
    public function index(Request $request, Tournament $tournament, Stage $stage): AnonymousResourceCollection
    {
        abort_unless($stage->tournament_id === $tournament->id, 404);

        $participants = $stage->participants()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('group_number'), fn ($q) => $q->where('group_number', $request->integer('group_number')))
            ->orderBy('seed')
            ->paginate($this->perPage($request, 20));

        return StageParticipantResource::collection($participants);
    }

    /**
     * Add a participant to a stage
     *
     * Tournament admin only. Allowed for stages whose incoming qualification rules are all `manual` (or stages with no incoming qualifications yet). Stages with auto-resolving rules (`top_n` / `top_n_per_group` / `all`) reject direct POST — the future qualification resolver owns the participant set and direct writes would conflict. For surgical overrides after auto-resolution, use PATCH on individual participants. Structure must be unlocked. Participant must match the tournament's `participant_type` and game; no duplicates per stage.
     */
    public function store(CreateStageParticipantRequest $request, Tournament $tournament, Stage $stage): JsonResponse
    {
        abort_unless($stage->tournament_id === $tournament->id, 404);
        $this->authorize('create', [StageParticipant::class, $stage]);

        abort_unless(
            StagePolicy::structureUnlocked($tournament),
            422,
            'Stage structure is locked once registration has closed.'
        );

        // Manual POST is only valid when the stage will not be auto-populated.
        // If any incoming qualification rule is non-manual, the resolver
        // owns the participant set — direct POST would conflict. PATCH on
        // an existing row is still allowed for surgical overrides.
        $hasAutoResolvingQualification = $stage->incomingQualifications()
            ->where('rule_type', '!=', 'manual')
            ->exists();

        abort_if(
            $hasAutoResolvingQualification,
            422,
            'This stage has automatic qualification rules; participants will be populated by the resolver. Use PATCH on individual participants for surgical overrides after resolution.'
        );

        $participant = $stage->participants()->create($request->validated());

        return (new StageParticipantResource($participant))->response()->setStatusCode(201);
    }

    /**
     * Update a participant in a stage
     *
     * Tournament admin only. Patch `seed`, `group_number`, `status`, `final_position`. Structure must be unlocked OR the change is operational (status / final_position) — those can fire after the bracket starts (driven by match advancement in commit 9).
     */
    public function update(
        UpdateStageParticipantRequest $request,
        Tournament $tournament,
        Stage $stage,
        StageParticipant $participant,
    ): StageParticipantResource {
        abort_unless($stage->tournament_id === $tournament->id, 404);
        abort_unless($participant->stage_id === $stage->id, 404);
        $this->authorize('update', $participant);

        $data = $request->validated();

        // Lock seed/group_number after structure freezes; status/final_position
        // remain mutable because they're set by match advancement.
        if (! StagePolicy::structureUnlocked($tournament)) {
            foreach (['seed', 'group_number'] as $field) {
                if (array_key_exists($field, $data)) {
                    abort(422, sprintf('Cannot change %s after registration has closed.', $field));
                }
            }
        }

        $participant->update($data);

        return new StageParticipantResource($participant);
    }

    /**
     * Remove a participant from a stage
     *
     * Tournament admin only. Hard-removes the row; only allowed while structure is unlocked. For mid-tournament withdrawals, PATCH `status=withdrawn` instead (preserves the row for standings).
     */
    public function destroy(
        Request $request,
        Tournament $tournament,
        Stage $stage,
        StageParticipant $participant,
    ): JsonResponse {
        abort_unless($stage->tournament_id === $tournament->id, 404);
        abort_unless($participant->stage_id === $stage->id, 404);
        $this->authorize('delete', $participant);

        abort_unless(
            StagePolicy::structureUnlocked($tournament),
            422,
            'Stage structure is locked once registration has closed.'
        );

        $participant->delete();

        return response()->json(['message' => 'Stage participant removed.']);
    }
}
