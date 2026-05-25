<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make('password'),
            'has_access' => true,
        ];
    }

    /**
     * Indicate that the user's access is disabled.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'has_access' => false,
        ]);
    }
}
