<?php

namespace Database\Factories;

use App\Models\FamilyMedicalUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FamilyMedicalUnit>
 */
class FamilyMedicalUnitFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'address' => fake()->address(),
        ];
    }
}
