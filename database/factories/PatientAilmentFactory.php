<?php

namespace Database\Factories;

use App\Enums\AilmentType;
use App\Models\Patient;
use App\Models\PatientAilment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PatientAilment>
 */
class PatientAilmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'patient_id' => Patient::factory(),
            'ailment_type' => fake()->randomElement(AilmentType::cases()),
            'diagnosed_at' => fake()->dateTimeBetween('-20 years'),
            'treatment_notes' => fake()->optional()->paragraph(),
        ];
    }
}
