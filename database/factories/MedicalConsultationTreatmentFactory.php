<?php

namespace Database\Factories;

use App\Models\MedicalConsultation;
use App\Models\MedicalConsultationTreatment;
use App\Models\Medication;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MedicalConsultationTreatment>
 */
class MedicalConsultationTreatmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'medical_consultation_id' => MedicalConsultation::factory(),
            'medication_id' => Medication::factory(),
            'quantity_dispensed' => fake()->numberBetween(1, 20),
            'dose' => fake()->randomElement(['500 mg', '10 ml', '1 tableta', '2 cápsulas']),
            'frequency' => fake()->randomElement(['Cada 8 horas', 'Cada 12 horas', 'Una vez al día']),
            'duration' => fake()->randomElement(['3 días', '5 días', '7 días', '10 días']),
        ];
    }
}
