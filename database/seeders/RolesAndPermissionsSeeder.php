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
 * grows per commit as new modules ship — every domain commit adds the
 * permissions its controllers consume here.
 *
 * Per DESIGN.md §3 the platform has two named roles plus an implicit "regular
 * user" with no role. App code should check hasRole('system_manager') /
 * hasRole('superadmin') for elevated paths.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            // Auth pass
            'users.view',
            'users.update',
            'roles.manage',

            // Catalog pass
            // games.manage covers create/update/delete (single perm for the
            // small surface area; split per-verb if granular grants are ever
            // needed for an org-staff role).
            'games.manage',

            // Identity-capabilities pass
            // tournaments.create is the "tournament builder" gate. Superadmin
            // and system_manager get it baked into their roles; regular users
            // earn it directly when their tournament_hosts row is approved
            // (and lose it on suspension / deletion). See
            // TournamentHostController::update + ::destroy.
            'tournaments.create',
        ];

        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $superadmin    = Role::firstOrCreate(['name' => 'superadmin',     'guard_name' => 'web']);
        $systemManager = Role::firstOrCreate(['name' => 'system_manager', 'guard_name' => 'web']);

        // Superadmin: everything.
        $superadmin->syncPermissions(Permission::all());

        // System manager: the system-level approver. Manages the catalog and
        // the user roster; does NOT auto-manage org-owned resources (org
        // ownership gates that, per DESIGN.md §5). Gets tournaments.create by
        // default — they can host tournaments without going through the host
        // application flow they themselves approve.
        $systemManager->syncPermissions([
            'users.view',
            'users.update',
            'games.manage',
            'tournaments.create',
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
