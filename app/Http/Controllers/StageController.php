<?php

namespace App\Http\Controllers;

use App\Http\Requests\Stage\CreateStageRequest;
use App\Http\Requests\Stage\ReorderStagesRequest;
use App\Http\Requests\Stage\UpdateStageRequest;
use App\Http\Resources\StageResource;
use App\Models\Stage;
use App\Models\Tournament;
use App\Policies\StagePolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class StageController extends Controller
{
    /**
     * List stages for a tournament
     *
     * Public list scoped to the parent tournament. Sorted by `sort_order`.
     */
    public function index(Request $request, Tournament $tournament): AnonymousResourceCollection
    {
        return StageResource::collection(
            $tournament->stages()->orderBy('sort_order')->paginate($this->perPage($request, 20))
        );
    }

    /**
     * Show a stage
     *
     * Public read for a single stage by id, scoped to the parent tournament.
     */
    public function show(Request $request, Tournament $tournament, Stage $stage): StageResource
    {
        abort_unless($stage->tournament_id === $tournament->id, 404);

        return new StageResource($stage);
    }

    /**
     * Create a stage
     *
     * Tournament admin only. Stage structure is locked once the tournament reaches `RegistrationClosed` — submissions past that 422. If `sort_order` is omitted, defaults to one past the current maximum so the new stage appends.
     */
    public function store(CreateStageRequest $request, Tournament $tournament): JsonResponse
    {
        $this->authorize('create', [Stage::class, $tournament]);

        abort_unless(
            StagePolicy::structureUnlocked($tournament),
            422,
            'Stage structure is locked once registration has closed.'
        );

        $data = $request->validated();
        $data['sort_order'] ??= ($tournament->stages()->max('sort_order') ?? -1) + 1;

        $stage = $tournament->stages()->create($data);

        return (new StageResource($stage))->response()->setStatusCode(201);
    }

    /**
     * Update a stage
     *
     * Tournament admin only. Locked once tournament is past `RegistrationOpen`. Patch any of `name`, `format`, `sort_order`, dates, `config`. Format change re-validates the config against the new format.
     */
    public function update(UpdateStageRequest $request, Tournament $tournament, Stage $stage): StageResource
    {
        abort_unless($stage->tournament_id === $tournament->id, 404);
        $this->authorize('update', $stage);

        abort_unless(
            StagePolicy::structureUnlocked($tournament),
            422,
            'Stage structure is locked once registration has closed.'
        );

        $stage->update($request->validated());

        return new StageResource($stage);
    }

    /**
     * Delete a stage
     *
     * Tournament admin only. Allowed only while the stage is `pending` AND the tournament is structurally unlocked (Draft / RegistrationOpen). Cascades to qualifications and stage_participants.
     */
    public function destroy(Request $request, Tournament $tournament, Stage $stage): JsonResponse
    {
        abort_unless($stage->tournament_id === $tournament->id, 404);
        $this->authorize('delete', $stage);

        abort_unless(
            StagePolicy::structureUnlocked($tournament),
            422,
            'Stage structure is locked once registration has closed.'
        );

        abort_unless(
            $stage->status === \App\Enums\StageStatus::Pending,
            422,
            'Only pending stages may be deleted.'
        );

        $stage->delete();

        return response()->json(['message' => 'Stage deleted.']);
    }

    /**
     * Bulk reorder stages
     *
     * Single atomic update of multiple stages' sort_order. Wrapped in a transaction so two stages never temporarily share a sort_order during the update (which would violate the unique constraint).
     */
    public function reorder(ReorderStagesRequest $request, Tournament $tournament): AnonymousResourceCollection
    {
        // The `reorder` method lives on StagePolicy (it's about reordering
        // stages even though the URL is keyed on the tournament). Passing
        // Stage::class first tells Laravel which policy to dispatch to.
        $this->authorize('reorder', [Stage::class, $tournament]);

        abort_unless(
            StagePolicy::structureUnlocked($tournament),
            422,
            'Stage structure is locked once registration has closed.'
        );

        DB::transaction(function () use ($request) {
            // Two-phase update to avoid unique-constraint collisions while
            // sort_orders are being shuffled: first push all rows to a
            // temporary out-of-band space, then write the desired final
            // sort_orders.
            $rows = $request->validated()['stages'];

            foreach ($rows as $i => $row) {
                Stage::where('id', $row['id'])->update(['sort_order' => 1_000_000 + $i]);
            }
            foreach ($rows as $row) {
                Stage::where('id', $row['id'])->update(['sort_order' => $row['sort_order']]);
            }
        });

        return StageResource::collection($tournament->stages()->get());
    }
}
