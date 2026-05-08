<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds one fixture user per role for local development and integration tests.
 *
 * Each account's password is its own email address — easier to paste in during
 * manual API testing. Only invoked from DatabaseSeeder in `local` / `testing`
 * environments; never in production.
 */
class DevUsersSeeder extends Seeder
{
    public function run(): void
    {
        $fixtures = [
            [
                'email'        => 'superadmin@mvesports.test',
                'name'         => 'Super Admin',
                'display_name' => 'superadmin',
                'role'         => 'superadmin',
            ],
            [
                'email'        => 'system-manager@mvesports.test',
                'name'         => 'System Manager',
                'display_name' => 'system-manager',
                'role'         => 'system_manager',
            ],
        ];

        foreach ($fixtures as $fixture) {
            $user = User::updateOrCreate(
                ['email' => $fixture['email']],
                [
                    'name'          => $fixture['name'],
                    'display_name'  => $fixture['display_name'],
                    'password'      => Hash::make($fixture['email']),
                    'date_of_birth' => '1995-01-01',
                    'country'       => 'MV',
                ],
            );
            $user->syncRoles([$fixture['role']]);
        }
    }
}
