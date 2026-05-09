<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        // Fixture users + demo state only in development / test environments.
        // Production seeds the roles/permissions catalogue only.
        //
        // DevDemoSeeder is self-contained (it idempotently re-calls
        // RolesAndPermissionsSeeder + DevUsersSeeder internally) so calling
        // it here produces the full dev state via plain `migrate:fresh
        // --seed` — no `--class=...` flag needed. The actual production-grade
        // demo seeder will replace this entry when it ships.
        if (app()->environment(['local', 'testing'])) {
            $this->call(DevDemoSeeder::class);
        }
    }
}
