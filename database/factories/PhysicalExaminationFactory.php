<?php

namespace Database\Factories;

use App\Models\MedicalConsultation;
use App\Models\PhysicalExamination;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PhysicalExamination>
 */
class PhysicalExaminationFactory extends Factory
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
            'neurological' => fake()->sentence(),
            'head_neck' => fake()->sentence(),
            'thorax_cardiopulmonary' => fake()->sentence(),
            'abdomen' => fake()->sentence(),
            'extremities' => fake()->sentence(),
            'cabinet_laboratory' => fake()->sentence(),
        ];
    }
}
