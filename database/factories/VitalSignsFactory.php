<?php

namespace Database\Factories;

use App\Models\MedicalConsultation;
use App\Models\VitalSigns;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VitalSigns>
 */
class VitalSignsFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $diastolic = fake()->numberBetween(60, 100);
        $systolic = fake()->numberBetween($diastolic + 10, 180);

        return [
            'medical_consultation_id' => MedicalConsultation::factory(),
            'weight' => fake()->randomFloat(2, 40, 150),
            'height' => fake()->randomFloat(2, 1.20, 2.10),
            'blood_pressure_systolic' => $systolic,
            'blood_pressure_diastolic' => $diastolic,
            'heart_rate' => fake()->numberBetween(50, 120),
            'respiratory_rate' => fake()->numberBetween(10, 30),
            'temperature' => fake()->randomFloat(1, 35.5, 39.5),
            'oxygen_saturation' => fake()->numberBetween(85, 100),
            'glasgow' => fake()->numberBetween(3, 15),
            'glucose' => fake()->optional()->numberBetween(60, 200),
        ];
    }
}
