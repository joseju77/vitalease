<?php

use App\Models\Enrollment;
use App\Models\FamilyMedicalUnit;
use App\Models\MedicalConsultation;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function validPatientAttributes(array $overrides = []): array
{
    return array_merge([
        'uuid' => (string) Str::uuid7(),
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'second_last_name' => null,
        'birth_date' => '1990-01-01',
        'sex_at_birth' => 1,
        'marital_status' => 1,
        'blood_type' => 1,
        'enrollment_id' => null,
        'enrollment_number' => null,
        'external_enrollment' => 'EXT-00001',
        'family_medical_unit_id' => null,
        'other_family_medical_unit' => 'Clinic X',
        'social_security_number' => fake()->unique()->numerify('###########'),
    ], $overrides);
}

describe('patient enum and format constraints', function () {
    it('rejects a sex_at_birth value outside the approved domain', function () {
        DB::table('patients')->insert(validPatientAttributes(['sex_at_birth' => 9]));
    })->throws(QueryException::class);

    it('rejects a marital_status value outside the approved domain', function () {
        DB::table('patients')->insert(validPatientAttributes(['marital_status' => 9]));
    })->throws(QueryException::class);

    it('rejects a blood_type value outside the approved domain', function () {
        DB::table('patients')->insert(validPatientAttributes(['blood_type' => 9]));
    })->throws(QueryException::class);

    it('rejects a social security number that is not exactly eleven digits', function () {
        DB::table('patients')->insert(validPatientAttributes(['social_security_number' => '123']));
    })->throws(QueryException::class);

    it('rejects a duplicate social security number', function () {
        $ssn = fake()->unique()->numerify('###########');

        DB::table('patients')->insert(validPatientAttributes(['social_security_number' => $ssn]));
        DB::table('patients')->insert(validPatientAttributes([
            'uuid' => (string) Str::uuid7(),
            'social_security_number' => $ssn,
        ]));
    })->throws(QueryException::class);
});

describe('patient foreign key constraints', function () {
    it('rejects an enrollment_id referencing an enrollment that does not exist', function () {
        DB::table('patients')->insert(validPatientAttributes([
            'enrollment_id' => 999_999,
            'enrollment_number' => '123456789',
            'external_enrollment' => null,
        ]));
    })->throws(QueryException::class);

    it('rejects a family_medical_unit_id referencing a unit that does not exist', function () {
        DB::table('patients')->insert(validPatientAttributes([
            'family_medical_unit_id' => 999_999,
            'other_family_medical_unit' => null,
        ]));
    })->throws(QueryException::class);

    it('rejects a duplicate enrollment_id and enrollment_number pair', function () {
        $enrollment = Enrollment::factory()->create();

        DB::table('patients')->insert(validPatientAttributes([
            'enrollment_id' => $enrollment->id,
            'enrollment_number' => '123456789',
            'external_enrollment' => null,
        ]));

        DB::table('patients')->insert(validPatientAttributes([
            'uuid' => (string) Str::uuid7(),
            'enrollment_id' => $enrollment->id,
            'enrollment_number' => '123456789',
            'external_enrollment' => null,
        ]));
    })->throws(QueryException::class);
});

describe('patient enrollment and family medical unit XOR constraints', function () {
    it('rejects a patient with both an internal and an external enrollment', function () {
        $enrollment = Enrollment::factory()->create();

        DB::table('patients')->insert(validPatientAttributes([
            'enrollment_id' => $enrollment->id,
            'enrollment_number' => '123456789',
            'external_enrollment' => 'EXT-00001',
        ]));
    })->throws(QueryException::class);

    it('rejects a patient with neither an internal nor an external enrollment', function () {
        DB::table('patients')->insert(validPatientAttributes([
            'enrollment_id' => null,
            'enrollment_number' => null,
            'external_enrollment' => null,
        ]));
    })->throws(QueryException::class);

    it('rejects a patient with only an enrollment_number and no enrollment_id', function () {
        DB::table('patients')->insert(validPatientAttributes([
            'enrollment_id' => null,
            'enrollment_number' => '123456789',
            'external_enrollment' => null,
        ]));
    })->throws(QueryException::class);

    it('rejects a patient with both a known and a free-text family medical unit', function () {
        $familyMedicalUnit = FamilyMedicalUnit::factory()->create();

        DB::table('patients')->insert(validPatientAttributes([
            'family_medical_unit_id' => $familyMedicalUnit->id,
            'other_family_medical_unit' => 'Clinic Y',
        ]));
    })->throws(QueryException::class);

    it('rejects a patient with neither a known nor a free-text family medical unit', function () {
        DB::table('patients')->insert(validPatientAttributes([
            'family_medical_unit_id' => null,
            'other_family_medical_unit' => null,
        ]));
    })->throws(QueryException::class);
});

describe('patient valid writes', function () {
    it('allows a patient with an external enrollment and a free-text family medical unit', function () {
        DB::table('patients')->insert(validPatientAttributes());

        expect(Patient::query()->count())->toBe(1);
    });

    it('allows a patient with a valid internal enrollment and known family medical unit', function () {
        $enrollment = Enrollment::factory()->create();
        $familyMedicalUnit = FamilyMedicalUnit::factory()->create();

        DB::table('patients')->insert(validPatientAttributes([
            'enrollment_id' => $enrollment->id,
            'enrollment_number' => '123456789',
            'external_enrollment' => null,
            'family_medical_unit_id' => $familyMedicalUnit->id,
            'other_family_medical_unit' => null,
        ]));

        expect(Patient::query()->count())->toBe(1);
    });
});

describe('patient disable state', function () {
    it('allows a patient with all three disable columns set', function () {
        $patient = Patient::factory()->disabled(reason: 'Registro duplicado')->create();

        expect($patient->fresh()->disabled_at)->not->toBeNull()
            ->and($patient->disabled_by_id)->not->toBeNull()
            ->and($patient->disable_reason)->toBe('Registro duplicado')
            ->and($patient->disabledBy)->toBeInstanceOf(User::class);
    });

    it('allows a patient with all three disable columns null', function () {
        $patient = Patient::factory()->create();

        expect($patient->fresh()->disabled_at)->toBeNull();
    });

    it('rejects disabled_at set without a reason', function () {
        DB::table('patients')->insert(validPatientAttributes([
            'disabled_at' => now(),
            'disabled_by_id' => User::factory()->create()->id,
            'disable_reason' => null,
        ]));
    })->throws(QueryException::class);

    it('rejects disabled_at set without the disabling user', function () {
        DB::table('patients')->insert(validPatientAttributes([
            'disabled_at' => now(),
            'disabled_by_id' => null,
            'disable_reason' => 'Motivo',
        ]));
    })->throws(QueryException::class);

    it('rejects a reason without disabled_at', function () {
        DB::table('patients')->insert(validPatientAttributes([
            'disabled_at' => null,
            'disabled_by_id' => null,
            'disable_reason' => 'Motivo',
        ]));
    })->throws(QueryException::class);

    it('rejects a disabling user that does not exist', function () {
        DB::table('patients')->insert(validPatientAttributes([
            'disabled_at' => now(),
            'disabled_by_id' => 999_999,
            'disable_reason' => 'Motivo',
        ]));
    })->throws(QueryException::class);

    it('derives is_disabled from disabled_at', function () {
        $active = Patient::factory()->create();
        $disabled = Patient::factory()->disabled()->create();

        expect($active->fresh()->is_disabled)->toBeFalse()
            ->and($disabled->fresh()->is_disabled)->toBeTrue();
    });

    it('keeps a disabled patient resolvable from its consultation', function () {
        $patient = Patient::factory()->disabled()->create();
        $consultation = MedicalConsultation::factory()->create(['patient_id' => $patient->id]);

        expect($consultation->fresh()->patient)->not->toBeNull()
            ->and($consultation->patient->is($patient))->toBeTrue();
    });
});
