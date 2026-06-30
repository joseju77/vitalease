<?php

namespace Database\Factories;

use App\Models\Patient;
use App\Models\PatientOtherAilment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PatientOtherAilment>
 */
class PatientOtherAilmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Each of surgeries, allergies, and others is independently optional,
     * but at least one MUST be non-null to satisfy chk_other_ailments_nonempty.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $fields = [
            fake()->optional()->paragraph(),
            fake()->optional()->paragraph(),
            fake()->optional()->paragraph(),
        ];

        if (array_filter($fields) === []) {
            $fields[array_rand($fields)] = fake()->paragraph();
        }

        return [
            'patient_id' => Patient::factory(),
            'surgeries' => $fields[0],
            'allergies' => $fields[1],
            'others' => $fields[2],
        ];
    }
}
