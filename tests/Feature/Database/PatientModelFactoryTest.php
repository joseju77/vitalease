<?php

use App\Enums\AilmentType;
use App\Enums\BloodType;
use App\Enums\ContraceptiveMethod;
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

describe('patient contact information model', function () {
    it('belongs to a patient', function () {
        $patient = Patient::factory()->create();
        $contactInformation = PatientContactInformation::factory()->create(['patient_id' => $patient->id]);

        expect($contactInformation->patient)->toBeInstanceOf(Patient::class)
            ->and($contactInformation->patient->id)->toBe($patient->id);
    });

    it('is reachable through the patient contactInformation relation', function () {
        $patient = Patient::factory()->create();
        $contactInformation = PatientContactInformation::factory()->create(['patient_id' => $patient->id]);

        expect($patient->contactInformation)->toBeInstanceOf(PatientContactInformation::class)
            ->and($patient->contactInformation->patient_id)->toBe($contactInformation->patient_id);
    });

    it('defaults to a null institutional email', function () {
        $contactInformation = PatientContactInformation::factory()->create();

        expect($contactInformation->institutional_email)->toBeNull();
    });

    it('attaches a unique institutional email via withInstitutionalEmail', function () {
        $contactInformation = PatientContactInformation::factory()->withInstitutionalEmail()->create();

        expect($contactInformation->institutional_email)->not->toBeNull();
    });

    it('attaches a known neighborhood via withNeighborhood', function () {
        $contactInformation = PatientContactInformation::factory()->withNeighborhood()->create();

        expect($contactInformation->neighborhood_id)->not->toBeNull()
            ->and($contactInformation->neighborhood)->toBeInstanceOf(Neighborhood::class);
    });
});

describe('patient emergency contact model', function () {
    it('belongs to a patient', function () {
        $patient = Patient::factory()->create();
        $emergencyContact = PatientEmergencyContact::factory()->create(['patient_id' => $patient->id]);

        expect($emergencyContact->patient)->toBeInstanceOf(Patient::class)
            ->and($emergencyContact->patient->id)->toBe($patient->id);
    });

    it('is reachable through the patient emergencyContacts relation', function () {
        $patient = Patient::factory()->create();
        PatientEmergencyContact::factory()->count(2)->create(['patient_id' => $patient->id]);

        expect($patient->emergencyContacts)->toHaveCount(2)
            ->and($patient->emergencyContacts->first())->toBeInstanceOf(PatientEmergencyContact::class);
    });

    it('casts kinship_type to the KinshipType enum', function () {
        $emergencyContact = PatientEmergencyContact::factory()->create();

        expect($emergencyContact->kinship_type)->toBeInstanceOf(KinshipType::class);
    });

    it('generates constraint-safe emergency contacts in bulk', function () {
        $emergencyContacts = PatientEmergencyContact::factory()->count(5)->create();

        expect($emergencyContacts)->toHaveCount(5);
    });
});

describe('patient ailment model', function () {
    it('belongs to a patient', function () {
        $patient = Patient::factory()->create();
        $ailment = PatientAilment::factory()->create(['patient_id' => $patient->id]);

        expect($ailment->patient)->toBeInstanceOf(Patient::class)
            ->and($ailment->patient->id)->toBe($patient->id);
    });

    it('is reachable through the patient ailments relation', function () {
        $patient = Patient::factory()->create();
        PatientAilment::factory()->create(['patient_id' => $patient->id, 'ailment_type' => 1]);
        PatientAilment::factory()->create(['patient_id' => $patient->id, 'ailment_type' => 2]);

        expect($patient->ailments)->toHaveCount(2)
            ->and($patient->ailments->first())->toBeInstanceOf(PatientAilment::class);
    });

    it('casts ailment_type to the AilmentType enum and diagnosed_at to a date', function () {
        $ailment = PatientAilment::factory()->create();

        expect($ailment->ailment_type)->toBeInstanceOf(AilmentType::class)
            ->and($ailment->diagnosed_at)->toBeInstanceOf(Carbon::class);
    });

    it('generates constraint-safe ailments in bulk with distinct ailment types', function () {
        $patient = Patient::factory()->create();
        $ailments = collect(AilmentType::cases())
            ->map(fn (AilmentType $type) => PatientAilment::factory()->create([
                'patient_id' => $patient->id,
                'ailment_type' => $type,
            ]));

        expect($ailments)->toHaveCount(6)
            ->and($patient->ailments)->toHaveCount(6);
    });
});

describe('patient other ailment model', function () {
    it('belongs to a patient', function () {
        $patient = Patient::factory()->create();
        $otherAilment = PatientOtherAilment::factory()->create(['patient_id' => $patient->id]);

        expect($otherAilment->patient)->toBeInstanceOf(Patient::class)
            ->and($otherAilment->patient->id)->toBe($patient->id);
    });

    it('is reachable through the patient otherAilments relation', function () {
        $patient = Patient::factory()->create();
        $otherAilment = PatientOtherAilment::factory()->create(['patient_id' => $patient->id]);

        expect($patient->otherAilments)->toBeInstanceOf(PatientOtherAilment::class)
            ->and($patient->otherAilments->patient_id)->toBe($otherAilment->patient_id);
    });

    it('always generates at least one non-null field', function () {
        $otherAilments = PatientOtherAilment::factory()->count(10)->create();

        expect($otherAilments->every(
            fn (PatientOtherAilment $ailment) => $ailment->surgeries !== null
                || $ailment->allergies !== null
                || $ailment->others !== null
        ))->toBeTrue();
    });
});

describe('patient gynecological history model', function () {
    it('belongs to a patient', function () {
        $patient = Patient::factory()->create(['sex_at_birth' => SexAtBirth::Female]);
        $history = PatientGynecologicalHistory::factory()->create(['patient_id' => $patient->id]);

        expect($history->patient)->toBeInstanceOf(Patient::class)
            ->and($history->patient->id)->toBe($patient->id);
    });

    it('is reachable through the patient gynecologicalHistory relation', function () {
        $patient = Patient::factory()->create(['sex_at_birth' => SexAtBirth::Female]);
        $history = PatientGynecologicalHistory::factory()->create(['patient_id' => $patient->id]);

        expect($patient->gynecologicalHistory)->toBeInstanceOf(PatientGynecologicalHistory::class)
            ->and($patient->gynecologicalHistory->patient_id)->toBe($history->patient_id);
    });

    it('casts attributes to the expected types', function () {
        $history = PatientGynecologicalHistory::factory()->create([
            'contraceptive_method' => 3,
            'last_pap_smear_date' => '2023-01-01',
            'last_pap_smear_was_positive' => false,
        ]);

        expect($history->has_cramps)->toBeBool()
            ->and($history->is_cycle_regular)->toBeBool()
            ->and($history->last_cycle_date)->toBeInstanceOf(Carbon::class)
            ->and($history->contraceptive_method)->toBeInstanceOf(ContraceptiveMethod::class)
            ->and($history->last_pap_smear_date)->toBeInstanceOf(Carbon::class)
            ->and($history->last_pap_smear_was_positive)->toBeBool();
    });

    it('attaches only to a Female patient by default', function () {
        $history = PatientGynecologicalHistory::factory()->create();

        expect($history->patient->sex_at_birth)->toBe(SexAtBirth::Female);
    });

    it('generates delivery counts that never exceed pregnancies', function () {
        $histories = PatientGynecologicalHistory::factory()->count(10)->create();

        expect($histories->every(
            fn (PatientGynecologicalHistory $history) => $history->vaginal_deliveries
                + $history->cesareans
                + $history->abortions <= $history->pregnancies
        ))->toBeTrue();
    });
});
