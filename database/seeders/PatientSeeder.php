<?php

namespace Database\Seeders;

use App\Models\Enrollment;
use App\Models\FamilyMedicalUnit;
use App\Models\Patient;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PatientSeeder extends Seeder
{
    /**
     * Number of development patients to seed.
     */
    private const int PATIENT_COUNT = 50;

    /**
     * Seed realistic development patients with their complete registration
     * aggregate.
     *
     * This is development data only: it does nothing outside the local
     * environment. Patients reference the existing enrollment, family
     * medical unit, and location catalogs (EnrollmentSeeder,
     * FamilyMedicalUnitSeeder, LocationSeeder) instead of creating new
     * catalog rows, so those seeders must run first. Roughly 70% of patients
     * use an internal enrollment and 50% a catalog family medical unit; the
     * rest use the free-text alternatives.
     */
    public function run(): void
    {
        if (! app()->isLocal()) {
            return;
        }

        $enrollmentIds = Enrollment::query()->pluck('id');
        $familyMedicalUnitIds = FamilyMedicalUnit::query()->pluck('id');
        $spanishFaker = fake('es_ES');

        DB::transaction(function () use ($enrollmentIds, $familyMedicalUnitIds, $spanishFaker) {
            Patient::factory()
                ->count(self::PATIENT_COUNT)
                ->withCompleteProfile()
                ->state(function () use ($enrollmentIds, $familyMedicalUnitIds, $spanishFaker) {
                    $attributes = [
                        'first_name' => $spanishFaker->firstName(),
                        'last_name' => $spanishFaker->lastName(),
                        'second_last_name' => fake()->boolean(95) ? $spanishFaker->lastName() : null,
                    ];

                    if ($enrollmentIds->isNotEmpty() && fake()->boolean(70)) {
                        $attributes += [
                            'enrollment_id' => $enrollmentIds->random(),
                            'enrollment_number' => fake()->unique()->numerify('#########'),
                            'external_enrollment' => null,
                        ];
                    }

                    if ($familyMedicalUnitIds->isNotEmpty() && fake()->boolean(50)) {
                        $attributes += [
                            'family_medical_unit_id' => $familyMedicalUnitIds->random(),
                            'other_family_medical_unit' => null,
                        ];
                    }

                    return $attributes;
                })
                ->create();
        });
    }
}
