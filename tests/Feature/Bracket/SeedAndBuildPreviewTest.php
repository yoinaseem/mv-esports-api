<?php

use App\Enums\TournamentStatus;
use App\Models\Stage;
use App\Models\StageQualification;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentRegistration;
use App\Models\User;

/**
 * Preview endpoint — dry-run of seed-and-build. Returns the bracket layout
 * the format-specific generator would produce, without persisting matches.
 * Used by the seeding UI to show the host what they'll get before they
 * commit (especially the attrition cases: SE byes, RR phantom byes,
 * DE not-buildable).
 */

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

function makeAdminAndTournamentInRegClosed(): array
{
    $admin = User::factory()->systemManager()->create();
    $tournament = Tournament::factory()->state([
        'status'             => TournamentStatus::RegistrationClosed,
        'participant_type'   => 'team',
        'created_by_user_id' => $admin->id,
    ])->create();
    return [$admin, $tournament];
}

function makeEntryStageWithQualification(Tournament $tournament, string $format, array $config): Stage
{
    $stage = Stage::factory()->for($tournament)->create([
        'format' => $format,
        'config' => $config,
    ]);
    StageQualification::factory()->fromRegistrations()->all()->create([
        'source_stage_id' => null,
        'target_stage_id' => $stage->id,
    ]);
    return $stage;
}

function makeApprovedRegistrations(Tournament $tournament, int $count, bool $assignSeeds = true): void
{
    for ($i = 1; $i <= $count; $i++) {
        $team = Team::factory()->create(['game_id' => $tournament->game_id]);
        TournamentRegistration::factory()->approved()->create([
            'tournament_id'    => $tournament->id,
            'participant_type' => 'team',
            'participant_id'   => $team->id,
            'seed'             => $assignSeeds ? $i : null,
        ]);
    }
}

// ---------------------------------------------------------------------------
// Status preconditions
// ---------------------------------------------------------------------------

test('preview rejects when tournament is in Draft', function () {
    $admin = User::factory()->systemManager()->create();
    $tournament = Tournament::factory()->draft()->create(['created_by_user_id' => $admin->id]);

    $this->actingAs($admin)
        ->getJson("/api/tournaments/{$tournament->id}/seed-and-build/preview")
        ->assertStatus(422)
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'registration_closed'));
});

test('preview rejects when tournament is in RegistrationOpen', function () {
    $admin = User::factory()->systemManager()->create();
    $tournament = Tournament::factory()->registrationOpen()->create(['created_by_user_id' => $admin->id]);

    $this->actingAs($admin)
        ->getJson("/api/tournaments/{$tournament->id}/seed-and-build/preview")
        ->assertStatus(422);
});

test('preview rejects when tournament has no entry stage', function () {
    [$admin, $tournament] = makeAdminAndTournamentInRegClosed();

    $this->actingAs($admin)
        ->getJson("/api/tournaments/{$tournament->id}/seed-and-build/preview")
        ->assertStatus(422)
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'no entry stage'));
});

test('preview rejects unauthenticated caller', function () {
    [$admin, $tournament] = makeAdminAndTournamentInRegClosed();
    makeEntryStageWithQualification($tournament, 'round_robin', ['groups' => 1, 'group_size' => 4]);

    $this->getJson("/api/tournaments/{$tournament->id}/seed-and-build/preview")
        ->assertStatus(401);
});

test('preview rejects non-admin caller', function () {
    [$_, $tournament] = makeAdminAndTournamentInRegClosed();
    makeEntryStageWithQualification($tournament, 'round_robin', ['groups' => 1, 'group_size' => 4]);

    $someone = User::factory()->create();

    $this->actingAs($someone)
        ->getJson("/api/tournaments/{$tournament->id}/seed-and-build/preview")
        ->assertStatus(403);
});

// ---------------------------------------------------------------------------
// Round-robin
// ---------------------------------------------------------------------------

test('preview RR with full capacity returns groups matching snake distribution', function () {
    [$admin, $tournament] = makeAdminAndTournamentInRegClosed();
    makeEntryStageWithQualification($tournament, 'round_robin', ['groups' => 2, 'group_size' => 4]);
    makeApprovedRegistrations($tournament, 8);

    $resp = $this->actingAs($admin)
        ->getJson("/api/tournaments/{$tournament->id}/seed-and-build/preview")
        ->assertStatus(200)
        ->json();

    $stage = $resp['stages'][0];
    expect($stage['format'])->toBe('round_robin');
    expect($stage['buildable'])->toBeTrue();
    expect($stage['approved_count'])->toBe(8);
    expect($stage['matches_total'])->toBe(12); // 6 per group × 2 groups

    // Snake distribution: G1 = seeds [1,4,5,8], G2 = [2,3,6,7]
    $group1Seeds = collect($stage['groups'][0]['participants'])->pluck('seed')->all();
    $group2Seeds = collect($stage['groups'][1]['participants'])->pluck('seed')->all();
    expect($group1Seeds)->toBe([1, 4, 5, 8]);
    expect($group2Seeds)->toBe([2, 3, 6, 7]);

    expect($stage['groups'][0]['has_phantom_bye'])->toBeFalse();
    expect($stage['groups'][0]['matches_in_group'])->toBe(6);
});

test('preview RR attrition produces groups of 3 with phantom byes', function () {
    [$admin, $tournament] = makeAdminAndTournamentInRegClosed();
    makeEntryStageWithQualification($tournament, 'round_robin', ['groups' => 2, 'group_size' => 4]);
    makeApprovedRegistrations($tournament, 6);

    $resp = $this->actingAs($admin)
        ->getJson("/api/tournaments/{$tournament->id}/seed-and-build/preview")
        ->assertStatus(200)
        ->json();

    $stage = $resp['stages'][0];
    expect($stage['buildable'])->toBeTrue();
    expect($stage['approved_count'])->toBe(6);
    expect($stage['matches_total'])->toBe(6); // 3 per group × 2 groups

    // Snake distribution of 6 into 2 buckets size 4 → G1 = [1,4,5], G2 = [2,3,6]
    $group1Seeds = collect($stage['groups'][0]['participants'])->pluck('seed')->all();
    expect($group1Seeds)->toBe([1, 4, 5]);
    expect($stage['groups'][0]['has_phantom_bye'])->toBeTrue(); // odd group size
    expect($stage['groups'][0]['matches_in_group'])->toBe(3);   // C(3,2)
});

test('preview RR with legs multiplies the match count', function () {
    [$admin, $tournament] = makeAdminAndTournamentInRegClosed();
    makeEntryStageWithQualification($tournament, 'round_robin', ['groups' => 1, 'group_size' => 4, 'legs' => 3]);
    makeApprovedRegistrations($tournament, 4);

    $resp = $this->actingAs($admin)
        ->getJson("/api/tournaments/{$tournament->id}/seed-and-build/preview")
        ->assertStatus(200)
        ->json();

    $stage = $resp['stages'][0];
    expect($stage['matches_total'])->toBe(18); // C(4,2)=6 × 3 legs
    expect($stage['config']['legs'])->toBe(3);
});

test('preview RR not buildable when participants exceed capacity (defensive)', function () {
    // Capacity is normally enforced at design time; this exercises the
    // generator's own guard if data slipped through somehow.
    [$admin, $tournament] = makeAdminAndTournamentInRegClosed();
    makeEntryStageWithQualification($tournament, 'round_robin', ['groups' => 1, 'group_size' => 4]);
    makeApprovedRegistrations($tournament, 6); // 6 > capacity 4

    $resp = $this->actingAs($admin)
        ->getJson("/api/tournaments/{$tournament->id}/seed-and-build/preview")
        ->assertStatus(200)
        ->json();

    $stage = $resp['stages'][0];
    expect($stage['buildable'])->toBeFalse();
    expect($stage['reason'])->toContain('exceed capacity');
});

// ---------------------------------------------------------------------------
// Single elimination
// ---------------------------------------------------------------------------

test('preview SE with power-of-2 count has no byes', function () {
    [$admin, $tournament] = makeAdminAndTournamentInRegClosed();
    makeEntryStageWithQualification($tournament, 'single_elim', ['best_of' => 3]);
    makeApprovedRegistrations($tournament, 8);

    $resp = $this->actingAs($admin)
        ->getJson("/api/tournaments/{$tournament->id}/seed-and-build/preview")
        ->assertStatus(200)
        ->json();

    $stage = $resp['stages'][0];
    expect($stage['format'])->toBe('single_elim');
    expect($stage['buildable'])->toBeTrue();
    expect($stage['bracket_size'])->toBe(8);
    expect($stage['byes'])->toBe(0);
    expect($stage['matches_total'])->toBe(7); // bracket_size - 1
    expect(count($stage['round_1']))->toBe(4);
    expect(collect($stage['round_1'])->every(fn ($m) => $m['kind'] === 'match'))->toBeTrue();
});

test('preview SE attrition pads to next power of 2 and assigns byes to top seeds', function () {
    [$admin, $tournament] = makeAdminAndTournamentInRegClosed();
    makeEntryStageWithQualification($tournament, 'single_elim', ['best_of' => 3]);
    makeApprovedRegistrations($tournament, 6);

    $resp = $this->actingAs($admin)
        ->getJson("/api/tournaments/{$tournament->id}/seed-and-build/preview")
        ->assertStatus(200)
        ->json();

    $stage = $resp['stages'][0];
    expect($stage['bracket_size'])->toBe(8);
    expect($stage['byes'])->toBe(2);
    expect($stage['matches_total'])->toBe(7);

    // Byes go to seeds 1 and 2 (the standard seed-order pattern positions
    // them against the missing seats 8 and 7).
    $byeRows = collect($stage['round_1'])->filter(fn ($m) => $m['kind'] === 'bye');
    expect($byeRows->count())->toBe(2);
    $byeSeeds = $byeRows->map(fn ($m) => $m['a']['seed'])->sort()->values()->all();
    expect($byeSeeds)->toBe([1, 2]);
});

test('preview SE with third_place_match adds one to matches_total', function () {
    [$admin, $tournament] = makeAdminAndTournamentInRegClosed();
    makeEntryStageWithQualification($tournament, 'single_elim', ['best_of' => 3, 'third_place_match' => true]);
    makeApprovedRegistrations($tournament, 4);

    $resp = $this->actingAs($admin)
        ->getJson("/api/tournaments/{$tournament->id}/seed-and-build/preview")
        ->assertStatus(200)
        ->json();

    $stage = $resp['stages'][0];
    expect($stage['matches_total'])->toBe(4); // 3 normal + 1 third-place
    expect($stage['config']['third_place_match'])->toBeTrue();
});

// ---------------------------------------------------------------------------
// Double elimination
// ---------------------------------------------------------------------------

test('preview DE with supported size returns buildable layout', function () {
    [$admin, $tournament] = makeAdminAndTournamentInRegClosed();
    makeEntryStageWithQualification($tournament, 'double_elim', ['best_of' => 3, 'grand_final_reset' => true]);
    makeApprovedRegistrations($tournament, 8);

    $resp = $this->actingAs($admin)
        ->getJson("/api/tournaments/{$tournament->id}/seed-and-build/preview")
        ->assertStatus(200)
        ->json();

    $stage = $resp['stages'][0];
    expect($stage['format'])->toBe('double_elim');
    expect($stage['buildable'])->toBeTrue();
    expect($stage['approved_count'])->toBe(8);
    // n=8: winners n-1=7, losers n-2=6, grand_final 1+1(reset)=2 → 15
    expect($stage['matches_total'])->toBe(15);
    expect($stage['bracket_counts']['winners'])->toBe(7);
    expect($stage['bracket_counts']['losers'])->toBe(6);
    expect($stage['bracket_counts']['grand_final'])->toBe(2);
    expect(count($stage['winners_round_1']))->toBe(4);
});

test('preview DE with unsupported size returns buildable=false and lists supported sizes', function () {
    [$admin, $tournament] = makeAdminAndTournamentInRegClosed();
    makeEntryStageWithQualification($tournament, 'double_elim', ['best_of' => 3]);
    makeApprovedRegistrations($tournament, 6);

    $resp = $this->actingAs($admin)
        ->getJson("/api/tournaments/{$tournament->id}/seed-and-build/preview")
        ->assertStatus(200)
        ->json();

    $stage = $resp['stages'][0];
    expect($stage['buildable'])->toBeFalse();
    expect($stage['reason'])->toContain('4, 8, 16, 32');
    expect($stage['supported_sizes'])->toBe([4, 8, 16, 32]);
});

// ---------------------------------------------------------------------------
// Participant snapshot
// ---------------------------------------------------------------------------

test('preview attaches participant names and registration_ids to snapshots', function () {
    [$admin, $tournament] = makeAdminAndTournamentInRegClosed();
    makeEntryStageWithQualification($tournament, 'round_robin', ['groups' => 1, 'group_size' => 4]);
    makeApprovedRegistrations($tournament, 4);

    $resp = $this->actingAs($admin)
        ->getJson("/api/tournaments/{$tournament->id}/seed-and-build/preview")
        ->assertStatus(200)
        ->json();

    $first = $resp['stages'][0]['groups'][0]['participants'][0];
    expect($first['seed'])->toBe(1);
    expect($first['participant_type'])->toBe('team');
    expect($first)->toHaveKey('participant_id');
    expect($first)->toHaveKey('registration_id');
    expect($first)->toHaveKey('name'); // team name pulled via morph
});
