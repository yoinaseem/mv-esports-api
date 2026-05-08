<?php

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

pest()->extend(Tests\TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        // Spatie caches role/permission lookups in-process; RefreshDatabase
        // resets the DB but not this cache, so stale IDs from prior tests
        // leak into the next one. Flush before seeding each test.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // The previous test may have hit a route protected by `auth:sanctum`,
        // and Laravel's Authenticate middleware calls AuthManager::shouldUse,
        // which writes `auth.defaults.guard` back to 'sanctum' in the shared
        // config repository. Spatie's Role/Permission lookups fall back to
        // that config when no guard is passed, so a stale 'sanctum' breaks
        // any subsequent Role::findByName() call (sanctum has no provider).
        // Reset it to 'web' before each test.
        config()->set('auth.defaults.guard', 'web');

        $this->seed(RolesAndPermissionsSeeder::class);
    })
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});
