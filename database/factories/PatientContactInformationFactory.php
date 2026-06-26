<?php

namespace Database\Factories;

use App\Models\Neighborhood;
use App\Models\Patient;
use App\Models\PatientContactInformation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PatientContactInformation>
 */
class PatientContactInformationFactory extends Factory
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
            'address' => fake()->streetAddress(),
            'phone_number' => fake()->e164PhoneNumber(),
            'personal_email' => fake()->unique()->safeEmail(),
            'institutional_email' => null,
            'neighborhood_id' => null,
        ];
    }

    /**
     * Attach a unique institutional email, satisfying the partial
     * unique index on non-null institutional_email values.
     */
    public function withInstitutionalEmail(): static
    {
        return $this->state(fn (array $attributes) => [
            'institutional_email' => fake()->unique()->safeEmail(),
        ]);
    }

    /**
     * Attach a known neighborhood.
     */
    public function withNeighborhood(): static
    {
        return $this->state(fn (array $attributes) => [
            'neighborhood_id' => Neighborhood::factory(),
        ]);
    }
}
