<?php

namespace Database\Factories;

use App\Models\Medication;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Medication>
 */
class MedicationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $minimumStock = fake()->numberBetween(5, 20);

        return [
            'name' => ucfirst(fake()->unique()->word()),
            'presentation' => fake()->randomElement(['Tableta', 'Cápsula', 'Jarabe', 'Solución inyectable', 'Crema']),
            'concentration' => fake()->numberBetween(1, 1000).' mg',
            'dispensing_unit' => fake()->randomElement(['unidad', 'ml', 'mg', 'frasco']),
            'current_stock' => fake()->numberBetween($minimumStock, $minimumStock * 10),
            'minimum_stock' => $minimumStock,
            'is_active' => true,
        ];
    }

    /**
     * Mark the medication as deactivated.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
