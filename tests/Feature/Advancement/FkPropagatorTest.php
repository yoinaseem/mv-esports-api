<?php

use App\Enums\MatchStatus;
use App\Models\Stage;
use App\Models\StageParticipant;
use App\Models\Team;
use App\Models\TournamentMatch;
use App\Services\Advancement\FkPropagator;

function makeAdvancementMatchPair(): array
{
    $stage = Stage::factory()->create();
    $teamA = Team::factory()->create(['game_id' => $stage->tournament->game_id]);
    $teamB = Team::factory()->create(['game_id' => $stage->tournament->game_id]);

    $upstream = TournamentMatch::factory()->create([
        'stage_id'           => $stage->id,
        'bracket_round'      => 1,
        'bracket_position'   => 0,
        'participant_a_type' => 'team',
        'participant_a_id'   => $teamA->id,
        'participant_b_type' => 'team',
        'participant_b_id'   => $teamB->id,
        'winner_participant_type' => 'team',
        'winner_participant_id'   => $teamA->id,
        'status'             => MatchStatus::Completed,
        'completed_at'       => now(),
    ]);
    $downstream = TournamentMatch::factory()->create([
        'stage_id'           => $stage->id,
        'bracket_round'      => 2,
        'bracket_position'   => 0,
        'participant_a_type' => null,
        'participant_a_id'   => null,
        'participant_b_type' => null,
        'participant_b_id'   => null,
        'status'             => MatchStatus::Pending,
    ]);
    $upstream->update([
        'winner_advances_to_match_id' => $downstream->id,
        'winner_advances_to_slot'     => 'a',
    ]);

    return [$upstream, $downstream, $teamA, $teamB];
}

test('propagate copies the winner into the target slot', function () {
    [$upstream, $downstream, $teamA] = makeAdvancementMatchPair();

    app(FkPropagator::class)->propagate($upstream);

    $downstream->refresh();
    expect($downstream->participant_a_type)->toBe('team');
    expect($downstream->participant_a_id)->toBe($teamA->id);
});

test('target stays Pending until both slots are filled', function () {
    [$upstream, $downstream] = makeAdvancementMatchPair();

    app(FkPropagator::class)->propagate($upstream);

    expect($downstream->fresh()->status)->toBe(MatchStatus::Pending);
});

test('target transitions Pending → Scheduled when both slots fill', function () {
    [$upstream, $downstream, $teamA, $teamB] = makeAdvancementMatchPair();
    // Pre-fill the other slot from a sibling match.
    $downstream->update([
        'participant_b_type' => 'team',
        'participant_b_id'   => $teamB->id,
    ]);

    app(FkPropagator::class)->propagate($upstream);

    expect($downstream->fresh()->status)->toBe(MatchStatus::Scheduled);
});

test('cancelled matches do not propagate', function () {
    [$upstream, $downstream] = makeAdvancementMatchPair();
    $upstream->update(['status' => MatchStatus::Cancelled]);

    app(FkPropagator::class)->propagate($upstream);

    expect($downstream->fresh()->participant_a_id)->toBeNull();
});

test('matches with no advancement target are no-ops', function () {
    [$upstream] = makeAdvancementMatchPair();
    $upstream->update(['winner_advances_to_match_id' => null]);

    expect(fn () => app(FkPropagator::class)->propagate($upstream))->not->toThrow(\Exception::class);
});

test('propagate is idempotent — re-calling does not throw or duplicate', function () {
    [$upstream, $downstream] = makeAdvancementMatchPair();

    app(FkPropagator::class)->propagate($upstream);
    app(FkPropagator::class)->propagate($upstream);

    expect($downstream->fresh()->participant_a_id)->not->toBeNull();
});

test('throws on slot conflict (different participant already in target slot)', function () {
    [$upstream, $downstream, $teamA, $teamB] = makeAdvancementMatchPair();
    // Pre-populate slot a with a different team.
    $stranger = Team::factory()->create(['game_id' => $upstream->stage->tournament->game_id]);
    $downstream->update([
        'participant_a_type' => 'team',
        'participant_a_id'   => $stranger->id,
    ]);

    expect(fn () => app(FkPropagator::class)->propagate($upstream))
        ->toThrow(\DomainException::class);
});

test('walkover bye (participant_b null) propagates the present participant only', function () {
    $stage = Stage::factory()->create();
    $teamA = Team::factory()->create(['game_id' => $stage->tournament->game_id]);

    $bye = TournamentMatch::factory()->create([
        'stage_id'                => $stage->id,
        'bracket_round'           => 1,
        'bracket_position'        => 0,
        'participant_a_type'      => 'team',
        'participant_a_id'        => $teamA->id,
        'participant_b_type'      => null,
        'participant_b_id'        => null,
        'winner_participant_type' => 'team',
        'winner_participant_id'   => $teamA->id,
        'status'                  => MatchStatus::Walkover,
        'completed_at'            => now(),
    ]);
    $downstream = TournamentMatch::factory()->create([
        'stage_id' => $stage->id,
        'bracket_round' => 2,
        'bracket_position' => 0,
        'participant_a_type' => null,
        'participant_a_id'   => null,
        'participant_b_type' => null,
        'participant_b_id'   => null,
        'status' => MatchStatus::Pending,
    ]);
    $bye->update([
        'winner_advances_to_match_id' => $downstream->id,
        'winner_advances_to_slot'     => 'a',
    ]);

    app(FkPropagator::class)->propagate($bye);

    $downstream->refresh();
    expect($downstream->participant_a_id)->toBe($teamA->id);
});

test('loser advancement copies the non-winning participant', function () {
    $stage = Stage::factory()->create();
    $teamA = Team::factory()->create(['game_id' => $stage->tournament->game_id]);
    $teamB = Team::factory()->create(['game_id' => $stage->tournament->game_id]);

    $upstream = TournamentMatch::factory()->create([
        'stage_id'                => $stage->id,
        'participant_a_type'      => 'team',
        'participant_a_id'        => $teamA->id,
        'participant_b_type'      => 'team',
        'participant_b_id'        => $teamB->id,
        'winner_participant_type' => 'team',
        'winner_participant_id'   => $teamA->id,
        'status'                  => MatchStatus::Completed,
    ]);
    $loserTarget = TournamentMatch::factory()->create([
        'stage_id' => $stage->id,
        'participant_a_type' => null, 'participant_a_id' => null,
        'participant_b_type' => null, 'participant_b_id' => null,
        'status' => MatchStatus::Pending,
    ]);
    $upstream->update([
        'loser_advances_to_match_id' => $loserTarget->id,
        'loser_advances_to_slot'     => 'a',
    ]);

    app(FkPropagator::class)->propagate($upstream);

    $loserTarget->refresh();
    expect($loserTarget->participant_a_id)->toBe($teamB->id);
});
