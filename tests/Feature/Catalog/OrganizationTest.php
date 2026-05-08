<?php

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\QueryException;

test('an organization belongs to its owner user', function () {
    $owner = User::factory()->create();
    $org   = Organization::factory()->create(['owner_user_id' => $owner->id]);

    expect($org->owner)->toBeInstanceOf(User::class);
    expect($org->owner->id)->toBe($owner->id);
});

test('two organizations cannot share a slug', function () {
    Organization::factory()->create(['slug' => 'mv-esports']);

    expect(fn () => Organization::factory()->create(['slug' => 'mv-esports']))
        ->toThrow(QueryException::class);
});

test('an organization soft-deletes rather than hard-deleting', function () {
    $org = Organization::factory()->create();
    $id  = $org->id;

    $org->delete();

    expect(Organization::find($id))->toBeNull();
    expect(Organization::withTrashed()->find($id))->not->toBeNull();
    expect(Organization::withTrashed()->find($id)->trashed())->toBeTrue();
});

test('deleting the owning user is restricted while the org exists', function () {
    $owner = User::factory()->create();
    Organization::factory()->create(['owner_user_id' => $owner->id]);

    expect(fn () => $owner->forceDelete())->toThrow(QueryException::class);
});
