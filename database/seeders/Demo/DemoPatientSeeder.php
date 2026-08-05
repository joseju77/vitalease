<?php

namespace Database\Seeders\Demo;

use App\Enums\AilmentType;
use App\Enums\BloodType;
use App\Enums\KinshipType;
use App\Enums\MaritalStatus;
use App\Enums\SexAtBirth;
use App\Models\Enrollment;
use App\Models\FamilyMedicalUnit;
use App\Models\Neighborhood;
use App\Models\Patient;
use App\Models\PatientAilment;
use App\Models\PatientContactInformation;
use App\Models\PatientEmergencyContact;
use App\Models\PatientGynecologicalHistory;
use App\Models\PatientOtherAilment;
use Database\Seeders\Concerns\LoadsCatalog;
use Faker\Generator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use JsonException;

class DemoPatientSeeder extends Seeder
{
    use LoadsCatalog;

    /**
     * Default number of demo patients to seed.
     */
    private const int PATIENT_COUNT = 80;

    /**
     * Seed realistic demo patients with an explicit, lorem-free
     * registration aggregate: contact information, 1-2 emergency contacts,
     * 0-3 ailments, optional other ailments, and gynecological history for
     * Female patients only.
     *
     * Requires the location, family medical unit, and enrollment catalogs
     * to already be seeded (LocationSeeder, FamilyMedicalUnitSeeder,
     * EnrollmentSeeder); patients reference those rows instead of creating
     * new ones. Roughly 70% of patients use an internal enrollment and 55%
     * a catalog family medical unit; the rest use realistic Mexican
     * alternatives from the curated catalog. Every field is set explicitly
     * (never `Patient::factory()->withCompleteProfile()`), because that
     * factory chain emits lorem ipsum and en_US names.
     *
     * @throws JsonException
     */
    public function run(int $count = self::PATIENT_COUNT): void
    {
        $people = $this->loadCatalog('demo/people.json');
        $faker = fake('es_ES');

        $enrollmentIds = Enrollment::query()->pluck('id');
        $familyMedicalUnitIds = FamilyMedicalUnit::query()->pluck('id');
        $neighborhoodIds = Neighborhood::query()->pluck('id');

        Patient::withoutSyncingToSearch(function () use ($count, $people, $faker, $enrollmentIds, $familyMedicalUnitIds, $neighborhoodIds) {
            DB::transaction(function () use ($count, $people, $faker, $enrollmentIds, $familyMedicalUnitIds, $neighborhoodIds) {
                for ($i = 0; $i < $count; $i++) {
                    $this->createPatient($people, $faker, $enrollmentIds, $familyMedicalUnitIds, $neighborhoodIds);
                }
            });
        });
    }

    /**
     * @param  array<string, mixed>  $people
     * @param  Collection<int, int>  $enrollmentIds
     * @param  Collection<int, int>  $familyMedicalUnitIds
     * @param  Collection<int, int>  $neighborhoodIds
     */
    private function createPatient(
        array $people,
        Generator $faker,
        Collection $enrollmentIds,
        Collection $familyMedicalUnitIds,
        Collection $neighborhoodIds,
    ): void {
        $sex = $faker->randomElement(SexAtBirth::cases());
        $firstName = $faker->randomElement($sex === SexAtBirth::Female ? $people['first_names_female'] : $people['first_names_male']);
        $lastName = $faker->randomElement($people['surnames']);
        $secondLastName = $faker->boolean(90) ? $faker->randomElement($people['surnames']) : null;
        $birthDate = $faker->dateTimeBetween('-85 years', '-18 years');
        $age = Carbon::instance($birthDate)->age;

        $patient = Patient::query()->create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'second_last_name' => $secondLastName,
            'birth_date' => $birthDate,
            'sex_at_birth' => $sex,
            'marital_status' => $faker->randomElement(MaritalStatus::cases()),
            'blood_type' => $faker->randomElement(BloodType::cases()),
            'social_security_number' => $faker->unique()->numerify('###########'),
            ...$this->enrollmentAttributes($people, $faker, $enrollmentIds),
            ...$this->familyMedicalUnitAttributes($people, $faker, $familyMedicalUnitIds),
        ]);

        PatientContactInformation::query()->create([
            'patient_id' => $patient->id,
            'address' => $faker->randomElement($people['streets']).' '.$faker->numberBetween(1, 999),
            'phone_number' => $this->mexicanPhoneNumber($faker),
            'personal_email' => $faker->unique()->safeEmail(),
            'institutional_email' => null,
            'neighborhood_id' => $neighborhoodIds->isNotEmpty() ? $neighborhoodIds->random() : null,
        ]);

        foreach (range(1, $faker->numberBetween(1, 2)) as $ignored) {
            $contactIsFemale = $faker->boolean();

            PatientEmergencyContact::query()->create([
                'patient_id' => $patient->id,
                'name' => trim(sprintf(
                    '%s %s %s',
                    $faker->randomElement($contactIsFemale ? $people['first_names_female'] : $people['first_names_male']),
                    $faker->randomElement($people['surnames']),
                    $faker->randomElement($people['surnames']),
                )),
                'phone_number' => $this->mexicanPhoneNumber($faker),
                'kinship_type' => $faker->randomElement(KinshipType::cases()),
            ]);
        }

        foreach ($faker->randomElements(AilmentType::cases(), $faker->numberBetween(0, 3)) as $ailmentType) {
            PatientAilment::query()->create([
                'patient_id' => $patient->id,
                'ailment_type' => $ailmentType,
                'diagnosed_at' => $faker->dateTimeBetween('-20 years', 'now'),
                'treatment_notes' => $faker->boolean(60) ? $faker->randomElement($people['ailment_notes']) : null,
            ]);
        }

        if ($faker->boolean(40)) {
            $this->createOtherAilments($patient, $people, $faker);
        }

        if ($sex === SexAtBirth::Female) {
            $this->createGynecologicalHistory($patient, $faker, $age);
        }
    }

    /**
     * @param  array<string, mixed>  $people
     * @param  Collection<int, int>  $enrollmentIds
     * @return array<string, mixed>
     */
    private function enrollmentAttributes(array $people, Generator $faker, Collection $enrollmentIds): array
    {
        if ($enrollmentIds->isNotEmpty() && $faker->boolean(70)) {
            return [
                'enrollment_id' => $enrollmentIds->random(),
                'enrollment_number' => $faker->unique()->numerify('#########'),
                'external_enrollment' => null,
            ];
        }

        return [
            'enrollment_id' => null,
            'enrollment_number' => null,
            'external_enrollment' => $faker->randomElement($people['external_enrollment_issuers']).'-'.$faker->unique()->numerify('########'),
        ];
    }

    /**
     * @param  array<string, mixed>  $people
     * @param  Collection<int, int>  $familyMedicalUnitIds
     * @return array<string, mixed>
     */
    private function familyMedicalUnitAttributes(array $people, Generator $faker, Collection $familyMedicalUnitIds): array
    {
        if ($familyMedicalUnitIds->isNotEmpty() && $faker->boolean(55)) {
            return [
                'family_medical_unit_id' => $familyMedicalUnitIds->random(),
                'other_family_medical_unit' => null,
            ];
        }

        return [
            'family_medical_unit_id' => null,
            'other_family_medical_unit' => $faker->randomElement($people['external_clinics']),
        ];
    }

    /**
     * Create the `patient_other_ailments` aggregate. Each field is
     * independently optional, but `chk_other_ailments_nonempty` requires at
     * least one to be set.
     *
     * @param  array<string, mixed>  $people
     */
    private function createOtherAilments(Patient $patient, array $people, Generator $faker): void
    {
        $surgeries = $faker->boolean(50) ? $faker->randomElement($people['surgery_notes']) : null;
        $allergies = $faker->boolean(50) ? $faker->randomElement($people['allergy_notes']) : null;
        $others = $faker->boolean(50) ? $faker->randomElement($people['other_notes']) : null;

        if ($surgeries === null && $allergies === null && $others === null) {
            $surgeries = $faker->randomElement($people['surgery_notes']);
        }

        PatientOtherAilment::query()->create([
            'patient_id' => $patient->id,
            'surgeries' => $surgeries,
            'allergies' => $allergies,
            'others' => $others,
        ]);
    }

    /**
     * Create Female-only gynecological history, reusing the factory's
     * constraint-safe pregnancy/delivery counts but explicitly clamping
     * `sexual_activity_start_age` to the patient's actual age.
     */
    private function createGynecologicalHistory(Patient $patient, Generator $faker, int $age): void
    {
        PatientGynecologicalHistory::factory()
            ->for($patient)
            ->state([
                'sexual_activity_start_age' => $faker->boolean(70)
                    ? $faker->numberBetween(15, max(15, min(40, $age)))
                    : null,
            ])
            ->create();
    }

    /**
     * A plausible Mexican E.164 phone number, unique within this seeding
     * run to satisfy the per-table phone number uniqueness constraints.
     */
    private function mexicanPhoneNumber(Generator $faker): string
    {
        return '+52'.$faker->unique()->numerify('##########');
    }
}
