<?php

namespace Database\Factories;

use App\Enums\BloodType;
use App\Enums\MaritalStatus;
use App\Enums\SexAtBirth;
use App\Models\Enrollment;
use App\Models\FamilyMedicalUnit;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Patient>
 */
class PatientFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'second_last_name' => fake()->optional(95)->lastName(),
            'birth_date' => fake()->dateTimeBetween('-80 years', '-18 years'),
            'sex_at_birth' => fake()->randomElement(SexAtBirth::cases()),
            'marital_status' => fake()->randomElement(MaritalStatus::cases()),
            'blood_type' => fake()->randomElement(BloodType::cases()),
            'enrollment_id' => null,
            'enrollment_number' => null,
            'external_enrollment' => fake()->bothify('EXT-#####'),
            'family_medical_unit_id' => null,
            'other_family_medical_unit' => fake()->company(),
            'social_security_number' => fake()->unique()->numerify('###########'),
        ];
    }

    /**
     * Attach an internal enrollment (enrollment_id + enrollment_number),
     * clearing the external enrollment alternative.
     */
    public function withEnrollment(): static
    {
        return $this->state(fn (array $attributes) => [
            'enrollment_id' => Enrollment::factory(),
            'enrollment_number' => fake()->unique()->numerify('#########'),
            'external_enrollment' => null,
        ]);
    }

    /**
     * Use an external enrollment reference, clearing the internal
     * enrollment alternative. This is the factory's default state.
     */
    public function withoutEnrollment(): static
    {
        return $this->state(fn (array $attributes) => [
            'enrollment_id' => null,
            'enrollment_number' => null,
            'external_enrollment' => fake()->bothify('EXT-#####'),
        ]);
    }

    /**
     * Attach a known family medical unit, clearing the free-text alternative.
     */
    public function withFamilyMedicalUnit(): static
    {
        return $this->state(fn (array $attributes) => [
            'family_medical_unit_id' => FamilyMedicalUnit::factory(),
            'other_family_medical_unit' => null,
        ]);
    }

    /**
     * Use a free-text family medical unit, clearing the known-unit
     * alternative. This is the factory's default state.
     */
    public function withOtherFamilyMedicalUnit(): static
    {
        return $this->state(fn (array $attributes) => [
            'family_medical_unit_id' => null,
            'other_family_medical_unit' => fake()->company(),
        ]);
    }
}
