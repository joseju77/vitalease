<?php

namespace Tests\Support;

use App\Enums\AilmentType;
use App\Enums\BloodType;
use App\Enums\KinshipType;
use App\Enums\MaritalStatus;
use App\Enums\Permission;
use App\Enums\SexAtBirth;
use App\Models\Neighborhood;
use App\Models\Patient;
use App\Models\User;
use App\Models\ZipCode;
use App\Services\Patients\PatientWriter;
use App\Support\Patients\PatientEditPayload;

/**
 * Builders shared by the patient management suites: a valid aggregate
 * payload, a patient registered through the real writer (so it carries its
 * audits), the edit payload of a stored patient, and the authorization
 * actors.
 */
final class PatientFixtures
{
    /**
     * A valid, self-contained registration aggregate. Unique values are
     * regenerated on each call.
     *
     * @param  array<string, mixed>  $overrides  Merged recursively over the payload.
     * @return array<string, mixed>
     */
    public static function payload(array $overrides = []): array
    {
        $zipCode = ZipCode::query()->first() ?? ZipCode::factory()->create();
        $neighborhood = Neighborhood::query()->where('zip_code', $zipCode->code)->first()
            ?? Neighborhood::factory()->create(['zip_code' => $zipCode->code]);

        return array_replace_recursive([
            'patient' => [
                'first_name' => fake()->firstName(),
                'last_name' => fake()->lastName(),
                'second_last_name' => fake()->lastName(),
                'birth_date' => '1990-05-10',
                'sex_at_birth' => SexAtBirth::Male->value,
                'marital_status' => MaritalStatus::Single->value,
                'blood_type' => BloodType::OPositive->value,
                'enrollment_id' => null,
                'enrollment_number' => null,
                'external_enrollment' => fake()->unique()->bothify('EXT-#####'),
                'family_medical_unit_id' => null,
                'other_family_medical_unit' => fake()->company(),
                'social_security_number' => fake()->unique()->numerify('###########'),
            ],
            'contact_information' => [
                'address' => fake()->streetAddress(),
                'phone_number' => '+5212220000001',
                'personal_email' => fake()->unique()->safeEmail(),
                'institutional_email' => null,
                'zip_code' => $zipCode->code,
                'neighborhood_id' => $neighborhood->id,
            ],
            'emergency_contacts' => [
                ['name' => 'Ana Lopez', 'phone_number' => '+5212220000002', 'kinship_type' => KinshipType::Parent->value],
                ['name' => 'Luis Ramos', 'phone_number' => '+5212220000003', 'kinship_type' => KinshipType::Friend->value],
            ],
            'ailments' => [
                ['ailment_type' => AilmentType::Diabetes->value, 'diagnosed_at' => '2015-01-01', 'treatment_notes' => null],
                ['ailment_type' => AilmentType::Hypertension->value, 'diagnosed_at' => '2018-03-04', 'treatment_notes' => 'Dieta'],
            ],
            'other_ailments' => ['surgeries' => 'Apendicectomia', 'allergies' => null, 'others' => null],
            'gynecological_history' => null,
        ], $overrides);
    }

    /**
     * A valid gynecological history block (Female patients only).
     *
     * @return array<string, mixed>
     */
    public static function gynecologicalHistory(): array
    {
        return [
            'menarche' => 12,
            'has_cramps' => true,
            'is_cycle_regular' => true,
            'cycle_intensity' => 5,
            'cycle_duration' => 5,
            'cycle_flow_level' => 3,
            'last_cycle_date' => '2026-08-01',
            'sexual_activity_start_age' => 18,
            'contraceptive_method' => null,
            'last_pap_smear_date' => null,
            'last_pap_smear_was_positive' => null,
            'pregnancies' => 2,
            'vaginal_deliveries' => 1,
            'cesareans' => 0,
            'abortions' => 0,
        ];
    }

    /**
     * Register a patient through the real writer, anonymously and audited,
     * exactly like the public form does.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function register(array $overrides = []): Patient
    {
        return app(PatientWriter::class)->register(self::payload($overrides));
    }

    /**
     * Register a patient and mark it disabled without writing audits.
     */
    public static function registerDisabled(string $reason = 'Registro duplicado'): Patient
    {
        $patient = self::register();
        $patient->forceFill([
            'disabled_at' => now(),
            'disabled_by_id' => User::factory()->create()->id,
            'disable_reason' => $reason,
        ])->saveQuietly();

        return $patient->refresh();
    }

    /**
     * The edit form payload of a stored patient, with the given top-level
     * sections replaced (not merged, so lists can shrink).
     *
     * @param  array<string, mixed>  $replace
     * @return array<string, mixed>
     */
    public static function editPayload(Patient $patient, array $replace = []): array
    {
        return array_replace(PatientEditPayload::for($patient->fresh()), $replace);
    }

    /**
     * The authorization actors of the patient matrix.
     */
    public static function actor(string $actor): ?User
    {
        return match ($actor) {
            'guest' => null,
            'no-permission' => User::factory()->create(),
            'view' => User::factory()->withPermissions(Permission::PatientsView)->create(),
            'update' => User::factory()->withPermissions(Permission::PatientsView, Permission::PatientsUpdate)->create(),
            'disable' => User::factory()->withPermissions(Permission::PatientsView, Permission::PatientsDisable)->create(),
            'audit' => User::factory()->withPermissions(Permission::PatientsView, Permission::PatientsAudit)->create(),
            'super-admin' => User::factory()->superAdmin()->create(),
        };
    }
}
