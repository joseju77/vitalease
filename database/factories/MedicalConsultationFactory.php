<?php

namespace Database\Factories;

use App\Enums\MedicalClassification;
use App\Enums\MedicalState;
use App\Models\MedicalConsultation;
use App\Models\MedicalRegulation;
use App\Models\Patient;
use App\Models\PhysicalExamination;
use App\Models\User;
use App\Models\VitalSigns;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MedicalConsultation>
 */
class MedicalConsultationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Only the core `medical_consultations` row is created by default; the
     * required `vitalSigns`/`physicalExamination` children (and the optional
     * `regulation`) are attached by the `withoutRegulation()`/
     * `withRegulation()` states below.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => $this->uniqueCode(),
            'current_condition' => fake()->sentence(),
            'diagnosis' => fake()->sentence(),
            'condition' => fake()->randomElement(MedicalState::cases()),
            'prognosis' => fake()->randomElement(MedicalState::cases()),
            'treatment' => [],
            'medical_classification' => fake()->randomElement(MedicalClassification::cases()),
            'physician_id' => User::factory(),
            'patient_id' => Patient::factory(),
        ];
    }

    /**
     * Attach the required vital signs and physical examination children,
     * without a regulation section.
     */
    public function withoutRegulation(): static
    {
        return $this->afterCreating(function (MedicalConsultation $consultation) {
            VitalSigns::factory()->create(['medical_consultation_id' => $consultation->id]);
            PhysicalExamination::factory()->create(['medical_consultation_id' => $consultation->id]);
        });
    }

    /**
     * Attach the required vital signs and physical examination children,
     * plus an optional regulation section.
     */
    public function withRegulation(): static
    {
        return $this->afterCreating(function (MedicalConsultation $consultation) {
            VitalSigns::factory()->create(['medical_consultation_id' => $consultation->id]);
            PhysicalExamination::factory()->create(['medical_consultation_id' => $consultation->id]);
            MedicalRegulation::factory()->create(['medical_consultation_id' => $consultation->id]);
        });
    }

    /**
     * Generate a unique code matching `MC-YYMMDD-NNNN` (14 chars).
     *
     * This is a factory-only placeholder: the server-side daily sequence
     * generator does not exist yet and is wired into the model's `creating`
     * hook in a later work unit.
     */
    private function uniqueCode(): string
    {
        return sprintf('MC-%s-%04d', now()->format('ymd'), fake()->unique()->numberBetween(1, 9999));
    }
}
