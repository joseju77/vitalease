<?php

namespace Database\Factories;

use App\Models\Municipality;
use App\Models\ZipCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ZipCode>
 */
class ZipCodeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->numerify('#####'),
            'municipality_id' => Municipality::factory(),
        ];
    }
}
