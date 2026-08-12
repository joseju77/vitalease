<?php

namespace Database\Factories;

use App\Enums\InventoryMovementType;
use App\Models\InventoryMovement;
use App\Models\MedicalConsultation;
use App\Models\Medication;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryMovement>
 */
class InventoryMovementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Defaults to a valid Entry movement: positive quantity, no consultation
     * link, no notes. Use the `dispensation()`, `dispensationReversal()`, or
     * `adjustment()` states for the other movement types, since each has a
     * different quantity sign and consultation-link requirement enforced by
     * the `inventory_movements` CHECK constraints.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quantity = fake()->numberBetween(1, 100);

        return [
            'medication_id' => Medication::factory(),
            'type' => InventoryMovementType::Entry,
            'quantity' => $quantity,
            'stock_after' => $quantity,
            'medical_consultation_id' => null,
            'medical_consultation_code' => null,
            'user_id' => User::factory(),
            'notes' => null,
            'occurred_at' => now(),
        ];
    }

    /**
     * A Dispensation movement: negative quantity, linked to a consultation.
     */
    public function dispensation(): static
    {
        return $this->state(function () {
            $consultation = MedicalConsultation::factory()->create();

            return [
                'type' => InventoryMovementType::Dispensation,
                'quantity' => -fake()->numberBetween(1, 50),
                'medical_consultation_id' => $consultation->id,
                'medical_consultation_code' => $consultation->code,
                'occurred_at' => $consultation->created_at,
            ];
        });
    }

    /**
     * A DispensationReversal movement: positive quantity, linked to a consultation.
     */
    public function dispensationReversal(): static
    {
        return $this->state(function () {
            $consultation = MedicalConsultation::factory()->create();

            return [
                'type' => InventoryMovementType::DispensationReversal,
                'quantity' => fake()->numberBetween(1, 50),
                'medical_consultation_id' => $consultation->id,
                'medical_consultation_code' => $consultation->code,
                'occurred_at' => $consultation->created_at,
            ];
        });
    }

    /**
     * An Adjustment movement: either sign, requires non-empty notes.
     */
    public function adjustment(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => InventoryMovementType::Adjustment,
            'quantity' => fake()->boolean() ? fake()->numberBetween(1, 20) : -fake()->numberBetween(1, 20),
            'medical_consultation_id' => null,
            'medical_consultation_code' => null,
            'notes' => fake()->sentence(),
        ]);
    }
}
