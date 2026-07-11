<?php

namespace Database\Factories;

use App\Enums\ContraceptiveMethod;
use App\Enums\SexAtBirth;
use App\Models\Patient;
use App\Models\PatientGynecologicalHistory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PatientGynecologicalHistory>
 */
class PatientGynecologicalHistoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Gynecological history is Female-only at the domain level (no
     * cross-table database check enforces it), so the default patient_id
     * factory forces sex_at_birth to Female. Pap smear fields are generated
     * as a pair and delivery counts never exceed pregnancies, matching
     * chk_last_pap_smear_consistency and chk_counts_consistent.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $pregnancies = fake()->boolean(80) ? fake()->numberBetween(1, 5) : 0;
        $vaginalDeliveries = $pregnancies > 0 ? fake()->numberBetween(0, $pregnancies) : 0;
        $remainingAfterDeliveries = $pregnancies - $vaginalDeliveries;
        $cesareans = $remainingAfterDeliveries > 0 ? fake()->numberBetween(0, $remainingAfterDeliveries) : 0;
        $remainingAfterCesareans = $remainingAfterDeliveries - $cesareans;
        $abortions = $remainingAfterCesareans > 0 ? fake()->numberBetween(0, $remainingAfterCesareans) : 0;

        $lastPapSmearDate = fake()->optional()->dateTimeBetween('-5 years');

        return [
            'patient_id' => Patient::factory()->state(['sex_at_birth' => SexAtBirth::Female]),
            'menarche' => fake()->numberBetween(9, 16),
            'has_cramps' => fake()->boolean(),
            'is_cycle_regular' => fake()->boolean(),
            'cycle_intensity' => fake()->numberBetween(1, 10),
            'cycle_duration' => fake()->numberBetween(3, 10),
            'cycle_flow_level' => fake()->numberBetween(1, 5),
            'last_cycle_date' => fake()->dateTimeBetween('-60 days'),
            'sexual_activity_start_age' => fake()->optional(70)->numberBetween(15, 40),
            'contraceptive_method' => fake()->optional()->randomElement(ContraceptiveMethod::cases()),
            'last_pap_smear_date' => $lastPapSmearDate,
            'last_pap_smear_was_positive' => $lastPapSmearDate ? fake()->boolean(10) : null,
            'pregnancies' => $pregnancies,
            'vaginal_deliveries' => $vaginalDeliveries,
            'cesareans' => $cesareans,
            'abortions' => $abortions,
        ];
    }
}
