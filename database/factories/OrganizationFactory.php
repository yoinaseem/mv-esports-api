<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'name'          => $name,
            'slug'          => Str::slug($name).'-'.fake()->unique()->numerify('####'),
            'logo_url'      => null,
            'description'   => fake()->optional()->sentence(),
            'owner_user_id' => User::factory(),
        ];
    }
}
