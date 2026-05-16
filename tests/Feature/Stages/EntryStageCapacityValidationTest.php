<?php

use App\Enums\TournamentStatus;
use App\Models\Stage;
use App\Models\StageQualification;
use App\Models\Tournament;
use App\Models\User;

/**
 * Cross-validates `tournament.max_participants` against the capacity of
 * any RR entry stage (`groups × group_size`). Three write paths can
 * violate the invariant — each is asserted here.
 */

function makeAdminAndDraftTournament(?int $max = null): array
{
    $admin = User::factory()->systemManager()->create();
    $tournament = Tournament::factory()->draft()->create([
        'created_by_user_id' => $admin->id,
        'max_participants'   => $max,
    ]);
    return [$admin, $tournament];
}

function makeRREntryStage(Tournament $tournament, int $groups, int $groupSize): Stage
{
    $stage = Stage::factory()->for($tournament)->create([
        'format' => 'round_robin',
        'config' => ['groups' => $groups, 'group_size' => $groupSize, 'best_of' => 3],
    ]);
    StageQualification::factory()->fromRegistrations()->all()->create([
        'source_stage_id' => null,
        'target_stage_id' => $stage->id,
    ]);
    return $stage;
}

// ---------------------------------------------------------------------------
// Stage PATCH
// ---------------------------------------------------------------------------

test('stage PATCH rejects shrinking RR entry capacity below max_participants', function () {
    [$admin, $tournament] = makeAdminAndDraftTournament(max: 8);
    $stage = makeRREntryStage($tournament, groups: 2, groupSize: 4); // capacity 8

    $this->actingAs($admin)
        ->patchJson("/api/tournaments/{$tournament->id}/stages/{$stage->id}", [
            'config' => ['groups' => 1, 'group_size' => 4, 'best_of' => 3], // capacity 4 ≠ max 8
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'seats 4') && str_contains($m, 'max_participants is 8'));

    // Transaction rolled back — config unchanged.
    expect($stage->fresh()->config)->toBe(['groups' => 2, 'group_size' => 4, 'best_of' => 3]);
});

test('stage PATCH rejects expanding RR entry capacity above max_participants', function () {
    // Symmetric case: host configures a group stage with more seats than the
    // tournament will ever fill. Structurally incoherent — reject.
    [$admin, $tournament] = makeAdminAndDraftTournament(max: 4);
    $stage = makeRREntryStage($tournament, groups: 1, groupSize: 4); // capacity 4, matches max

    $this->actingAs($admin)
        ->patchJson("/api/tournaments/{$tournament->id}/stages/{$stage->id}", [
            'config' => ['groups' => 2, 'group_size' => 4, 'best_of' => 3], // capacity 8 ≠ max 4
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'seats 8') && str_contains($m, 'max_participants is 4'));

    expect($stage->fresh()->config)->toBe(['groups' => 1, 'group_size' => 4, 'best_of' => 3]);
});

test('stage PATCH accepts RR entry capacity exactly matching max_participants', function () {
    [$admin, $tournament] = makeAdminAndDraftTournament(max: 8);
    $stage = makeRREntryStage($tournament, groups: 2, groupSize: 4); // capacity 8 = max 8

    // No-op-ish PATCH that keeps capacity at 8 (rearranged: 1 group of 8).
    $this->actingAs($admin)
        ->patchJson("/api/tournaments/{$tournament->id}/stages/{$stage->id}", [
            'config' => ['groups' => 1, 'group_size' => 8, 'best_of' => 3],
        ])
        ->assertStatus(200);

    expect($stage->fresh()->config)->toBe(['groups' => 1, 'group_size' => 8, 'best_of' => 3]);
});

test('stage PATCH skips capacity check on SE entry stage (no fixed capacity)', function () {
    [$admin, $tournament] = makeAdminAndDraftTournament(max: 8);
    $stage = Stage::factory()->for($tournament)->create([
        'format' => 'single_elim',
        'config' => ['third_place_match' => true, 'best_of' => 3],
    ]);
    StageQualification::factory()->fromRegistrations()->all()->create([
        'source_stage_id' => null,
        'target_stage_id' => $stage->id,
    ]);

    $this->actingAs($admin)
        ->patchJson("/api/tournaments/{$tournament->id}/stages/{$stage->id}", [
            'config' => ['third_place_match' => false, 'best_of' => 3],
        ])
        ->assertStatus(200);
});

test('stage PATCH skips capacity check on non-entry RR stage', function () {
    [$admin, $tournament] = makeAdminAndDraftTournament(max: 8);
    $entry = makeRREntryStage($tournament, groups: 2, groupSize: 4);

    // Downstream RR stage fed by qualification from $entry, not from registrations.
    $downstream = Stage::factory()->for($tournament)->create([
        'format'     => 'round_robin',
        'sort_order' => 1,
        'config'     => ['groups' => 1, 'group_size' => 4, 'best_of' => 3], // capacity 4
    ]);
    StageQualification::factory()->topNPerGroup(perGroup: 2)->create([
        'source_stage_id' => $entry->id,
        'target_stage_id' => $downstream->id,
    ]);

    // Shrinking the downstream stage further is fine — it's not an entry stage.
    $this->actingAs($admin)
        ->patchJson("/api/tournaments/{$tournament->id}/stages/{$downstream->id}", [
            'config' => ['groups' => 1, 'group_size' => 2, 'best_of' => 3],
        ])
        ->assertStatus(200);
});

test('stage PATCH skips capacity check when max_participants is null', function () {
    [$admin, $tournament] = makeAdminAndDraftTournament(max: null);
    $stage = makeRREntryStage($tournament, groups: 2, groupSize: 4);

    $this->actingAs($admin)
        ->patchJson("/api/tournaments/{$tournament->id}/stages/{$stage->id}", [
            'config' => ['groups' => 1, 'group_size' => 2, 'best_of' => 3],
        ])
        ->assertStatus(200);
});

// ---------------------------------------------------------------------------
// Tournament PATCH (max_participants)
// ---------------------------------------------------------------------------

test('tournament PATCH rejects bumping max_participants above entry stage capacity', function () {
    [$admin, $tournament] = makeAdminAndDraftTournament(max: 4);
    makeRREntryStage($tournament, groups: 1, groupSize: 4); // capacity 4

    $this->actingAs($admin)
        ->patchJson("/api/tournaments/{$tournament->id}", [
            'max_participants' => 8, // entry stage seats 4 ≠ 8
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'seats 4') && str_contains($m, 'max_participants is 8'));

    expect($tournament->fresh()->max_participants)->toBe(4);
});

test('tournament PATCH rejects lowering max_participants below entry stage capacity', function () {
    // Symmetric case: shrinking max below the configured stage capacity leaves
    // the stage over-provisioned. Reject.
    [$admin, $tournament] = makeAdminAndDraftTournament(max: 8);
    makeRREntryStage($tournament, groups: 2, groupSize: 4); // capacity 8 = max 8 (valid initial)

    $this->actingAs($admin)
        ->patchJson("/api/tournaments/{$tournament->id}", [
            'max_participants' => 4, // entry stage seats 8 ≠ 4
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'seats 8') && str_contains($m, 'max_participants is 4'));

    expect($tournament->fresh()->max_participants)->toBe(8);
});

test('tournament PATCH accepts max_participants exactly matching entry stage capacity', function () {
    [$admin, $tournament] = makeAdminAndDraftTournament(max: 4);
    makeRREntryStage($tournament, groups: 2, groupSize: 4); // capacity 8

    $this->actingAs($admin)
        ->patchJson("/api/tournaments/{$tournament->id}", [
            'max_participants' => 8, // matches capacity exactly
        ])
        ->assertStatus(200);

    expect($tournament->fresh()->max_participants)->toBe(8);
});

// ---------------------------------------------------------------------------
// Qualification POST (the moment a stage becomes an entry stage)
// ---------------------------------------------------------------------------

test('qualification POST rejects promoting an undersized RR stage to entry', function () {
    [$admin, $tournament] = makeAdminAndDraftTournament(max: 8);
    $stage = Stage::factory()->for($tournament)->create([
        'format' => 'round_robin',
        'config' => ['groups' => 1, 'group_size' => 4, 'best_of' => 3], // capacity 4 ≠ max 8
    ]);

    $this->actingAs($admin)
        ->postJson("/api/tournaments/{$tournament->id}/stages/{$stage->id}/qualifications", [
            'rule_type'       => 'all',
            'rule_config'     => [],
            'source_stage_id' => null,
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'seats 4') && str_contains($m, 'max_participants is 8'));

    // Transaction rolled back — qualification not persisted.
    expect($stage->incomingQualifications()->count())->toBe(0);
});

test('qualification POST rejects promoting an oversized RR stage to entry', function () {
    // Symmetric: host's stage is too big relative to declared max.
    [$admin, $tournament] = makeAdminAndDraftTournament(max: 4);
    $stage = Stage::factory()->for($tournament)->create([
        'format' => 'round_robin',
        'config' => ['groups' => 2, 'group_size' => 4, 'best_of' => 3], // capacity 8 ≠ max 4
    ]);

    $this->actingAs($admin)
        ->postJson("/api/tournaments/{$tournament->id}/stages/{$stage->id}/qualifications", [
            'rule_type'       => 'all',
            'rule_config'     => [],
            'source_stage_id' => null,
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'seats 8') && str_contains($m, 'max_participants is 4'));

    expect($stage->incomingQualifications()->count())->toBe(0);
});

test('qualification POST accepts entry promotion when capacity matches max', function () {
    [$admin, $tournament] = makeAdminAndDraftTournament(max: 8);
    $stage = Stage::factory()->for($tournament)->create([
        'format' => 'round_robin',
        'config' => ['groups' => 2, 'group_size' => 4, 'best_of' => 3], // capacity 8
    ]);

    $this->actingAs($admin)
        ->postJson("/api/tournaments/{$tournament->id}/stages/{$stage->id}/qualifications", [
            'rule_type'       => 'all',
            'rule_config'     => [],
            'source_stage_id' => null,
        ])
        ->assertStatus(201);
});

// ---------------------------------------------------------------------------
// Double-elim entry stage — max_participants must be in {4, 8, 16, 32}
// ---------------------------------------------------------------------------

test('DE entry stage rejects max_participants not in {4, 8, 16, 32}', function () {
    [$admin, $tournament] = makeAdminAndDraftTournament(max: 10);
    $stage = Stage::factory()->for($tournament)->create([
        'format' => 'double_elim',
        'config' => ['best_of' => 3],
    ]);

    $this->actingAs($admin)
        ->postJson("/api/tournaments/{$tournament->id}/stages/{$stage->id}/qualifications", [
            'rule_type'       => 'all',
            'rule_config'     => [],
            'source_stage_id' => null,
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'double_elim') && str_contains($m, '4, 8, 16, 32'));
});

test('DE entry stage accepts max_participants in {4, 8, 16, 32}', function () {
    [$admin, $tournament] = makeAdminAndDraftTournament(max: 8);
    $stage = Stage::factory()->for($tournament)->create([
        'format' => 'double_elim',
        'config' => ['best_of' => 3],
    ]);

    $this->actingAs($admin)
        ->postJson("/api/tournaments/{$tournament->id}/stages/{$stage->id}/qualifications", [
            'rule_type'       => 'all',
            'rule_config'     => [],
            'source_stage_id' => null,
        ])
        ->assertStatus(201);
});

test('tournament PATCH rejects max_participants not in DE-supported sizes when DE is the entry format', function () {
    [$admin, $tournament] = makeAdminAndDraftTournament(max: 8);
    $stage = Stage::factory()->for($tournament)->create([
        'format' => 'double_elim',
        'config' => ['best_of' => 3],
    ]);
    StageQualification::factory()->fromRegistrations()->all()->create([
        'source_stage_id' => null,
        'target_stage_id' => $stage->id,
    ]);

    $this->actingAs($admin)
        ->patchJson("/api/tournaments/{$tournament->id}", [
            'max_participants' => 10,
        ])
        ->assertStatus(422);

    expect($tournament->fresh()->max_participants)->toBe(8);
});

// ---------------------------------------------------------------------------
// Shrinking below approved count (3b)
// ---------------------------------------------------------------------------

test('stage PATCH rejects shrinking RR entry capacity below current approved count', function () {
    // null max so we exercise the approved-count check independently of
    // the max-vs-capacity check (which would otherwise fire first).
    [$admin, $tournament] = makeAdminAndDraftTournament(max: null);
    $tournament->update(['status' => \App\Enums\TournamentStatus::RegistrationOpen]);
    $stage = makeRREntryStage($tournament, groups: 2, groupSize: 4); // capacity 8

    // 6 approved registrations against a stage that seats 8.
    for ($i = 1; $i <= 6; $i++) {
        $team = \App\Models\Team::factory()->create(['game_id' => $tournament->game_id]);
        \App\Models\TournamentRegistration::factory()->approved()->create([
            'tournament_id'  => $tournament->id,
            'participant_id' => $team->id,
            'seed'           => $i,
        ]);
    }

    // Try to shrink stage to capacity 4 — fewer seats than the 6 already approved.
    $this->actingAs($admin)
        ->patchJson("/api/tournaments/{$tournament->id}/stages/{$stage->id}", [
            'config' => ['groups' => 1, 'group_size' => 4, 'best_of' => 3], // capacity 4
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', fn ($m) => str_contains($m, '6 registrations are already approved'));

    expect($stage->fresh()->config)->toBe(['groups' => 2, 'group_size' => 4, 'best_of' => 3]);
});

test('stage PATCH accepts shrinking RR capacity to exactly the approved count', function () {
    [$admin, $tournament] = makeAdminAndDraftTournament(max: null);
    $tournament->update(['status' => \App\Enums\TournamentStatus::RegistrationClosed]);
    $stage = makeRREntryStage($tournament, groups: 2, groupSize: 4);

    for ($i = 1; $i <= 6; $i++) {
        $team = \App\Models\Team::factory()->create(['game_id' => $tournament->game_id]);
        \App\Models\TournamentRegistration::factory()->approved()->create([
            'tournament_id'  => $tournament->id,
            'participant_id' => $team->id,
            'seed'           => $i,
        ]);
    }

    $this->actingAs($admin)
        ->patchJson("/api/tournaments/{$tournament->id}/stages/{$stage->id}", [
            'config' => ['groups' => 2, 'group_size' => 3, 'best_of' => 3], // capacity 6 = approved
        ])
        ->assertStatus(200);
});

// ---------------------------------------------------------------------------
// Tiered rule: strict equality during Draft, relaxed once registration opens
// ---------------------------------------------------------------------------

test('open-registration verb gates with strict cap-vs-max equality (rejects mismatch)', function () {
    // Construct a Draft tournament with cap !== max — factories bypass the
    // design-time validator so we can simulate "snuck in through a different
    // path." The lock-in gate at open-registration must catch this.
    [$admin, $tournament] = makeAdminAndDraftTournament(max: 8);
    $stage = Stage::factory()->for($tournament)->create([
        'format' => 'round_robin',
        'config' => ['groups' => 1, 'group_size' => 4, 'best_of' => 3], // capacity 4 ≠ max 8
    ]);
    StageQualification::factory()->fromRegistrations()->all()->create([
        'source_stage_id' => null,
        'target_stage_id' => $stage->id,
    ]);

    $this->actingAs($admin)
        ->postJson("/api/tournaments/{$tournament->id}/open-registration")
        ->assertStatus(422)
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'seats 4') && str_contains($m, 'max_participants is 8'));

    // Status unchanged — transition didn't run.
    expect($tournament->fresh()->status)->toBe(\App\Enums\TournamentStatus::Draft);
});

test('open-registration verb passes when cap === max', function () {
    [$admin, $tournament] = makeAdminAndDraftTournament(max: 8);
    $stage = makeRREntryStage($tournament, groups: 2, groupSize: 4); // capacity 8 === max 8

    $this->actingAs($admin)
        ->postJson("/api/tournaments/{$tournament->id}/open-registration")
        ->assertStatus(200);

    expect($tournament->fresh()->status)->toBe(\App\Enums\TournamentStatus::RegistrationOpen);
});

test('stage PATCH during RegistrationOpen accepts shrinking cap below max (attrition flow)', function () {
    // Realistic flow: host declared max=8, opened registration, only 4 have
    // signed up so far. Decides to tighten the bracket to 1 group of 4.
    // Post-design-time, max becomes advisory; cap is governed by reality.
    [$admin, $tournament] = makeAdminAndDraftTournament(max: 8);
    $stage = makeRREntryStage($tournament, groups: 2, groupSize: 4);
    $tournament->update(['status' => \App\Enums\TournamentStatus::RegistrationOpen]);

    $this->actingAs($admin)
        ->patchJson("/api/tournaments/{$tournament->id}/stages/{$stage->id}", [
            'config' => ['groups' => 1, 'group_size' => 4, 'best_of' => 3], // capacity 4 < max 8
        ])
        ->assertStatus(200);

    expect($stage->fresh()->config)->toBe(['groups' => 1, 'group_size' => 4, 'best_of' => 3]);
});

test('stage PATCH during RegistrationOpen accepts expanding cap above max', function () {
    // Symmetric: cap > max during RegistrationOpen is also allowed. The
    // host can over-provision seats; phantom byes cover the gap at build.
    [$admin, $tournament] = makeAdminAndDraftTournament(max: 4);
    $stage = makeRREntryStage($tournament, groups: 1, groupSize: 4);
    $tournament->update(['status' => \App\Enums\TournamentStatus::RegistrationOpen]);

    $this->actingAs($admin)
        ->patchJson("/api/tournaments/{$tournament->id}/stages/{$stage->id}", [
            'config' => ['groups' => 2, 'group_size' => 4, 'best_of' => 3], // capacity 8 > max 4
        ])
        ->assertStatus(200);

    expect($stage->fresh()->config)->toBe(['groups' => 2, 'group_size' => 4, 'best_of' => 3]);
});

test('stage PATCH during RegistrationClosed accepts shrinking cap (recovery window)', function () {
    // The flow the user flagged: max=8 declared, only 6 signed up and were
    // approved, registration closed. Host shrinks cap to 6 to get a tidy
    // 1-group-of-6 bracket without touching max.
    [$admin, $tournament] = makeAdminAndDraftTournament(max: 8);
    $stage = makeRREntryStage($tournament, groups: 2, groupSize: 4);
    $tournament->update(['status' => \App\Enums\TournamentStatus::RegistrationClosed]);

    for ($i = 1; $i <= 6; $i++) {
        $team = \App\Models\Team::factory()->create(['game_id' => $tournament->game_id]);
        \App\Models\TournamentRegistration::factory()->approved()->create([
            'tournament_id'  => $tournament->id,
            'participant_id' => $team->id,
            'seed'           => $i,
        ]);
    }

    $this->actingAs($admin)
        ->patchJson("/api/tournaments/{$tournament->id}/stages/{$stage->id}", [
            'config' => ['groups' => 1, 'group_size' => 6, 'best_of' => 3], // capacity 6 < max 8
        ])
        ->assertStatus(200);

    // max_participants intentionally stays at 8 — declared intent preserved.
    expect($tournament->fresh()->max_participants)->toBe(8);
    expect($stage->fresh()->config)->toBe(['groups' => 1, 'group_size' => 6, 'best_of' => 3]);
});

test('tournament PATCH during RegistrationOpen accepts max different from cap', function () {
    // Symmetric to the stage PATCH relaxation — max is now decoupled from
    // cap post-design-time. Host can lower max as reality sets in.
    [$admin, $tournament] = makeAdminAndDraftTournament(max: 8);
    makeRREntryStage($tournament, groups: 2, groupSize: 4); // cap 8 === max 8 initially
    $tournament->update(['status' => \App\Enums\TournamentStatus::RegistrationOpen]);

    $this->actingAs($admin)
        ->patchJson("/api/tournaments/{$tournament->id}", [
            'max_participants' => 4, // cap stays 8; max drops to 4
        ])
        ->assertStatus(200);

    expect($tournament->fresh()->max_participants)->toBe(4);
});

test('approved-count guard still fires post-registration regardless of status', function () {
    // The approved-count check is universal (always enforced). Stage PATCH
    // during RegistrationClosed cannot shrink cap below the approved count
    // even though the cap-vs-max check has relaxed.
    [$admin, $tournament] = makeAdminAndDraftTournament(max: null);
    $stage = makeRREntryStage($tournament, groups: 2, groupSize: 4);
    $tournament->update(['status' => \App\Enums\TournamentStatus::RegistrationClosed]);

    for ($i = 1; $i <= 7; $i++) {
        $team = \App\Models\Team::factory()->create(['game_id' => $tournament->game_id]);
        \App\Models\TournamentRegistration::factory()->approved()->create([
            'tournament_id'  => $tournament->id,
            'participant_id' => $team->id,
            'seed'           => $i,
        ]);
    }

    $this->actingAs($admin)
        ->patchJson("/api/tournaments/{$tournament->id}/stages/{$stage->id}", [
            'config' => ['groups' => 1, 'group_size' => 4, 'best_of' => 3], // capacity 4 < 7 approved
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', fn ($m) => str_contains($m, '7 registrations are already approved'));
});
