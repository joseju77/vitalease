<?php

namespace Database\Factories;

use App\Enums\KinshipType;
use App\Models\Patient;
use App\Models\PatientEmergencyContact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PatientEmergencyContact>
 */
class PatientEmergencyContactFactory extends Factory
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
            'name' => fake()->name(),
            'phone_number' => fake()->unique()->e164PhoneNumber(),
            'kinship_type' => fake()->randomElement(KinshipType::cases()),
        ];
    }
}
