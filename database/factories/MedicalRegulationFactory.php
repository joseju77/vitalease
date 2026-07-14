<?php

namespace Database\Factories;

use App\Enums\TransferType;
use App\Models\MedicalConsultation;
use App\Models\MedicalRegulation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MedicalRegulation>
 */
class MedicalRegulationFactory extends Factory
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
            'transfer_type' => fake()->randomElement(TransferType::cases()),
            'ambulance_registration' => fake()->optional()->bothify('AMB-#####'),
            'regulation_number' => fake()->optional()->bothify('REG-#####'),
            'clinic_id' => fake()->optional()->bothify('CLN-#####'),
            'regulated_at' => now(),
            'receiver_physician' => fake()->optional()->name(),
        ];
    }
}
