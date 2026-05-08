<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name'              => fake()->name(),
            'display_name'      => fake()->userName(),
            'email'             => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password'          => static::$password ??= Hash::make('password'),
            'date_of_birth'     => fake()->dateTimeBetween('-50 years', '-18 years')->format('Y-m-d'),
            'country'           => 'MV',
            'remember_token'    => Str::random(10),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function superadmin(): static
    {
        return $this->afterCreating(fn (User $user) => $user->assignRole('superadmin'));
    }

    public function systemManager(): static
    {
        return $this->afterCreating(fn (User $user) => $user->assignRole('system_manager'));
    }
}
