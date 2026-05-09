<?php

use App\Enums\TournamentStatus;
use App\Models\MatchEvent;
use App\Models\Organization;
use App\Models\Player;
use App\Models\Tournament;
use App\Models\TournamentHost;
use App\Models\TournamentMatch;
use App\Models\TournamentRegistration;
use App\Models\User;
use App\Services\User\UserAnonymisationService;

test('anonymises name, display_name, email, and replaces password with an unauthable random hash', function () {
    $user = User::factory()->create([
        'name'         => 'Real Name',
        'display_name' => 'realname',
        'email'        => 'real@example.test',
        'password'     => bcrypt('original-password'),
    ]);
    $originalPasswordHash = $user->fresh()->password;

    app(UserAnonymisationService::class)->anonymise($user);

    $fresh = User::withTrashed()->find($user->id);
    expect($fresh->name)->toBe('[deleted user]');
    expect($fresh->display_name)->toBe('[deleted user]');
    expect($fresh->email)->toBe("deleted-{$user->id}@anonymised.local");
    // Password is replaced with a fresh random hash — different from the
    // original, can't be authenticated against (the plaintext was never
    // exposed).
    expect($fresh->password)->not->toBe($originalPasswordHash);
    expect($fresh->password)->not->toBeNull();
});

test('soft-deletes the user (deleted_at set, row preserved)', function () {
    $user = User::factory()->create();

    app(UserAnonymisationService::class)->anonymise($user);

    expect(User::find($user->id))->toBeNull(); // hidden from default scope
    expect(User::withTrashed()->find($user->id))->not->toBeNull();
    expect(User::withTrashed()->find($user->id)->deleted_at)->not->toBeNull();
});

test('detaches player rows from user and replaces gamertag', function () {
    $user = User::factory()->create();
    $player = Player::factory()->create(['user_id' => $user->id, 'gamertag' => 'pro_gamer']);

    app(UserAnonymisationService::class)->anonymise($user);

    $player->refresh();
    expect($player->user_id)->toBeNull();
    expect($player->gamertag)->toBe('[deleted user]');
});

test('anonymises the tournament_hosts row when present', function () {
    $user = User::factory()->create();
    $host = TournamentHost::factory()->create([
        'user_id'      => $user->id,
        'display_name' => 'host_alice',
        'bio'          => 'Lengthy host bio with personal info.',
    ]);

    app(UserAnonymisationService::class)->anonymise($user);

    $host->refresh();
    expect($host->display_name)->toBe('[deleted user]');
    expect($host->bio)->toBeNull();
});

test('nulls FKs on tournaments.created_by and approved_by', function () {
    $user = User::factory()->create();
    $tournament = Tournament::factory()->create([
        'created_by_user_id'  => $user->id,
        'approved_by_user_id' => $user->id,
    ]);

    app(UserAnonymisationService::class)->anonymise($user);

    $tournament->refresh();
    expect($tournament->created_by_user_id)->toBeNull();
    expect($tournament->approved_by_user_id)->toBeNull();
});

test('nulls FK on tournament_hosts.approved_by_user_id', function () {
    $user = User::factory()->create();
    $approver = User::factory()->create();
    $hostRow = TournamentHost::factory()->create([
        'user_id'             => $user->id,
        'approved_by_user_id' => $approver->id,
    ]);
    // Now anonymise the approver — the host row's approved_by should null.
    app(UserAnonymisationService::class)->anonymise($approver);

    $hostRow->refresh();
    expect($hostRow->approved_by_user_id)->toBeNull();
});

test('nulls FK on match_events.created_by_user_id', function () {
    $user = User::factory()->create();
    $match = TournamentMatch::factory()->create();
    $event = MatchEvent::factory()->create([
        'match_id'           => $match->id,
        'created_by_user_id' => $user->id,
    ]);

    app(UserAnonymisationService::class)->anonymise($user);

    $event->refresh();
    expect($event->created_by_user_id)->toBeNull();
});

test('preserves tournament_registrations.registered_by_user_id (FK is NOT NULL)', function () {
    $user = User::factory()->create();
    $tournament = Tournament::factory()->create();
    $reg = TournamentRegistration::factory()->create([
        'tournament_id'         => $tournament->id,
        'registered_by_user_id' => $user->id,
    ]);

    app(UserAnonymisationService::class)->anonymise($user);

    $reg->refresh();
    // FK still points at the (now soft-deleted, anonymised) user.
    expect($reg->registered_by_user_id)->toBe($user->id);
});

test('revokes all sanctum tokens', function () {
    $user = User::factory()->create();
    $user->createToken('test1');
    $user->createToken('test2');
    expect($user->tokens()->count())->toBe(2);

    app(UserAnonymisationService::class)->anonymise($user);

    expect($user->tokens()->count())->toBe(0);
});

test('throws when user owns active organisations', function () {
    $user = User::factory()->create();
    Organization::factory()->create(['owner_user_id' => $user->id]);

    expect(fn () => app(UserAnonymisationService::class)->anonymise($user))
        ->toThrow(\DomainException::class);
});

test('soft-deleted organisations do not block deletion', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create(['owner_user_id' => $user->id]);
    $org->delete(); // soft-delete the org

    // Should NOT throw — only active (non-soft-deleted) ownership blocks.
    expect(fn () => app(UserAnonymisationService::class)->anonymise($user))
        ->not->toThrow(\Exception::class);
});

test('strips roles and direct permissions on anonymisation', function () {
    $user = User::factory()->systemManager()->create();
    $user->givePermissionTo('tournaments.create');
    expect($user->fresh()->hasRole('system_manager'))->toBeTrue();

    app(UserAnonymisationService::class)->anonymise($user);

    $fresh = User::withTrashed()->find($user->id);
    expect($fresh->getRoleNames()->toArray())->toBe([]);
    expect($fresh->getDirectPermissions()->toArray())->toBe([]);
});

test('suspends the tournament_hosts row on anonymisation', function () {
    $user = User::factory()->create();
    $host = TournamentHost::factory()->create([
        'user_id' => $user->id,
        'status'  => 'approved',
    ]);

    app(UserAnonymisationService::class)->anonymise($user);

    expect($host->fresh()->status)->toBe('suspended');
});
