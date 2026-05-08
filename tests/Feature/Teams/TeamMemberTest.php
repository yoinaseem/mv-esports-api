<?php

use App\Models\Player;
use App\Models\Team;
use App\Models\TeamMember;
use Illuminate\Database\QueryException;

test('a team member belongs to a team and a player', function () {
    $member = TeamMember::factory()->create();

    expect($member->team)->toBeInstanceOf(Team::class);
    expect($member->player)->toBeInstanceOf(Player::class);
});

test('factory state methods captain/substitute/left set their respective fields', function () {
    expect(TeamMember::factory()->captain()->create()->role)->toBe('captain');
    expect(TeamMember::factory()->substitute()->create()->role)->toBe('substitute');
    expect(TeamMember::factory()->left()->create()->left_at)->not->toBeNull();
});

test('the active scope excludes left members', function () {
    $team = Team::factory()->create();
    TeamMember::factory()->for($team)->count(2)->create();
    TeamMember::factory()->for($team)->left()->create();

    expect($team->members()->count())->toBe(3);
    expect($team->activeMembers()->count())->toBe(2);
    expect(TeamMember::active()->where('team_id', $team->id)->count())->toBe(2);
});

test('deleting a team cascades to its members', function () {
    $team = Team::factory()->create();
    TeamMember::factory()->for($team)->count(3)->create();
    expect(TeamMember::where('team_id', $team->id)->count())->toBe(3);

    $team->forceDelete();

    expect(TeamMember::where('team_id', $team->id)->count())->toBe(0);
});

test('hard-deleting a player who is on a team is RESTRICTed', function () {
    $member = TeamMember::factory()->create();
    $player = $member->player;

    expect(fn () => $player->delete())->toThrow(QueryException::class);
});

test('joined_at and left_at cast as datetime', function () {
    $member = TeamMember::factory()->left()->create();

    expect($member->joined_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
    expect($member->left_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('a player can be on multiple teams (across different games)', function () {
    $player1 = Player::factory()->create();
    $player2 = Player::factory()->create(['user_id' => $player1->user_id]); // same user, different game

    TeamMember::factory()->create(['player_id' => $player1->id]);
    TeamMember::factory()->create(['player_id' => $player2->id]);

    expect(TeamMember::count())->toBe(2);
});
