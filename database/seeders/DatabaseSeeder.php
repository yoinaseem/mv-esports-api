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

        // Fixture users only in development / test environments. Production
        // seeds the roles/permissions catalogue only.
        if (app()->environment(['local', 'testing'])) {
            $this->call(DevUsersSeeder::class);
        }
    }
}
