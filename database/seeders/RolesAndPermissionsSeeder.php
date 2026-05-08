<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Roles and permissions for mv-esports.
 *
 * Idempotent: firstOrCreate + syncPermissions, safe to re-run. The catalogue
 * is intentionally minimal on Day 1 — only auth-adjacent permissions are
 * seeded. Tournament / host / organisation permissions land alongside their
 * controllers in later passes.
 *
 * Per DESIGN.md §3 the platform has two named roles plus an implicit "regular
 * user" with no role. App code should check hasRole('manager') /
 * hasRole('superadmin') for elevated paths.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            'users.view',
            'users.update',
            'roles.manage',
        ];

        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $superadmin    = Role::firstOrCreate(['name' => 'superadmin',     'guard_name' => 'web']);
        $systemManager = Role::firstOrCreate(['name' => 'system_manager', 'guard_name' => 'web']);

        $superadmin->syncPermissions(Permission::all());
        $systemManager->syncPermissions(['users.view', 'users.update']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
