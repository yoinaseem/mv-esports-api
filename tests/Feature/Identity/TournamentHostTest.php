<?php

use App\Models\Organization;
use App\Models\TournamentHost;
use App\Models\User;
use Illuminate\Database\QueryException;

test('a tournament host belongs to a user and optionally an organisation', function () {
    $host = TournamentHost::factory()->create();
    expect($host->user)->toBeInstanceOf(User::class);
    expect($host->organization)->toBeNull();

    $org    = Organization::factory()->create();
    $hosted = TournamentHost::factory()->create(['organization_id' => $org->id]);
    expect($hosted->organization)->toBeInstanceOf(Organization::class);
});

test('a fresh host application defaults to status=pending and unset approver fields', function () {
    $host = TournamentHost::factory()->create();

    expect($host->status)->toBe('pending');
    expect($host->approved_by_user_id)->toBeNull();
    expect($host->approved_at)->toBeNull();
});

test('a user can have at most one tournament host row (unique user_id)', function () {
    $user = User::factory()->create();
    TournamentHost::factory()->for($user)->create();

    expect(fn () => TournamentHost::factory()->for($user)->create())
        ->toThrow(QueryException::class);
});

test('the approved() factory state populates status, approver, and approved_at', function () {
    $approver = User::factory()->create();
    $host     = TournamentHost::factory()->approved($approver)->create();

    expect($host->status)->toBe('approved');
    expect($host->approved_by_user_id)->toBe($approver->id);
    expect($host->approved_at)->not->toBeNull();
});

test('approver relationship resolves to the approving user', function () {
    $approver = User::factory()->create();
    $host     = TournamentHost::factory()->approved($approver)->create();

    expect($host->approver)->toBeInstanceOf(User::class);
    expect($host->approver->id)->toBe($approver->id);
});

test('deleting the host user cascades to their tournament host row', function () {
    $user = User::factory()->create();
    TournamentHost::factory()->for($user)->create();

    $user->forceDelete();

    expect(TournamentHost::where('user_id', $user->id)->count())->toBe(0);
});

test('deleting an organisation nulls out the host organization_id (host survives)', function () {
    $org  = Organization::factory()->create();
    $host = TournamentHost::factory()->create(['organization_id' => $org->id]);

    $org->forceDelete();
    $host->refresh();

    expect($host->organization_id)->toBeNull();
});

test('deleting an approver user nulls approved_by_user_id without dropping the host record', function () {
    $approver = User::factory()->create();
    $host     = TournamentHost::factory()->approved($approver)->create();

    $approver->forceDelete();
    $host->refresh();

    expect($host->approved_by_user_id)->toBeNull();
    expect($host->status)->toBe('approved');
});
