<?php

use App\Enums\BloodType;
use App\Enums\MaritalStatus;
use App\Enums\SexAtBirth;
use App\Models\Enrollment;
use App\Models\FamilyMedicalUnit;
use App\Models\Patient;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

describe('patient model', function () {
    it('generates a UUID v7 when creating a patient', function () {
        $patient = Patient::factory()->create();

        expect($patient->uuid)->not->toBeNull()
            ->and(substr((string) $patient->uuid, 14, 1))->toBe('7');
    });

    it('uses uuid as the route key', function () {
        $patient = Patient::factory()->create();

        expect($patient->getRouteKeyName())->toBe('uuid');
    });

    it('casts attributes to the expected types', function () {
        $patient = Patient::factory()->create();

        expect($patient->birth_date)->toBeInstanceOf(Carbon::class)
            ->and($patient->sex_at_birth)->toBeInstanceOf(SexAtBirth::class)
            ->and($patient->marital_status)->toBeInstanceOf(MaritalStatus::class)
            ->and($patient->blood_type)->toBeInstanceOf(BloodType::class)
            ->and($patient->created_at)->toBeInstanceOf(CarbonImmutable::class)
            ->and($patient->updated_at)->toBeInstanceOf(CarbonImmutable::class);
    });

    it('belongs to an enrollment', function () {
        $enrollment = Enrollment::factory()->create();
        $patient = Patient::factory()->withEnrollment()->create(['enrollment_id' => $enrollment->id]);

        expect($patient->enrollment)->toBeInstanceOf(Enrollment::class)
            ->and($patient->enrollment->id)->toBe($enrollment->id);
    });

    it('belongs to a family medical unit', function () {
        $familyMedicalUnit = FamilyMedicalUnit::factory()->create();
        $patient = Patient::factory()->withFamilyMedicalUnit()->create(['family_medical_unit_id' => $familyMedicalUnit->id]);

        expect($patient->familyMedicalUnit)->toBeInstanceOf(FamilyMedicalUnit::class)
            ->and($patient->familyMedicalUnit->id)->toBe($familyMedicalUnit->id);
    });
});

describe('patient factory states', function () {
    it('defaults to an external enrollment and a free-text family medical unit', function () {
        $patient = Patient::factory()->create();

        expect($patient->enrollment_id)->toBeNull()
            ->and($patient->enrollment_number)->toBeNull()
            ->and($patient->external_enrollment)->not->toBeNull()
            ->and($patient->family_medical_unit_id)->toBeNull()
            ->and($patient->other_family_medical_unit)->not->toBeNull();
    });

    it('attaches an internal enrollment via withEnrollment', function () {
        $patient = Patient::factory()->withEnrollment()->create();

        expect($patient->enrollment_id)->not->toBeNull()
            ->and($patient->enrollment_number)->not->toBeNull()
            ->and($patient->external_enrollment)->toBeNull()
            ->and($patient->enrollment)->toBeInstanceOf(Enrollment::class);
    });

    it('reverts to an external enrollment via withoutEnrollment', function () {
        $patient = Patient::factory()->withEnrollment()->withoutEnrollment()->create();

        expect($patient->enrollment_id)->toBeNull()
            ->and($patient->enrollment_number)->toBeNull()
            ->and($patient->external_enrollment)->not->toBeNull();
    });

    it('attaches a known family medical unit via withFamilyMedicalUnit', function () {
        $patient = Patient::factory()->withFamilyMedicalUnit()->create();

        expect($patient->family_medical_unit_id)->not->toBeNull()
            ->and($patient->other_family_medical_unit)->toBeNull()
            ->and($patient->familyMedicalUnit)->toBeInstanceOf(FamilyMedicalUnit::class);
    });

    it('reverts to a free-text family medical unit via withOtherFamilyMedicalUnit', function () {
        $patient = Patient::factory()->withFamilyMedicalUnit()->withOtherFamilyMedicalUnit()->create();

        expect($patient->family_medical_unit_id)->toBeNull()
            ->and($patient->other_family_medical_unit)->not->toBeNull();
    });

    it('generates constraint-safe patients in bulk', function () {
        $patients = Patient::factory()->count(5)->create();

        expect($patients)->toHaveCount(5);
    });
});
