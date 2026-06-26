<?php

use App\Models\Neighborhood;
use App\Models\Patient;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function validContactInformationAttributes(int $patientId, array $overrides = []): array
{
    return array_merge([
        'patient_id' => $patientId,
        'address' => '123 Main St',
        'phone_number' => '+11234567890',
        'personal_email' => 'person@example.com',
        'institutional_email' => null,
        'neighborhood_id' => null,
    ], $overrides);
}

function validEmergencyContactAttributes(int $patientId, array $overrides = []): array
{
    return array_merge([
        'patient_id' => $patientId,
        'name' => 'Jane Doe',
        'phone_number' => '+11234567890',
        'kinship_type' => 1,
    ], $overrides);
}

describe('patient contact information constraints', function () {
    it('rejects a phone number that is not E.164 format', function () {
        $patient = Patient::factory()->create();

        DB::table('patient_contact_information')->insert(
            validContactInformationAttributes($patient->id, ['phone_number' => '1234567890'])
        );
    })->throws(QueryException::class);

    it('rejects a duplicate patient_id because the relation is one-to-one', function () {
        $patient = Patient::factory()->create();

        DB::table('patient_contact_information')->insert(validContactInformationAttributes($patient->id));
        DB::table('patient_contact_information')->insert(
            validContactInformationAttributes($patient->id, ['personal_email' => 'other@example.com'])
        );
    })->throws(QueryException::class);

    it('rejects contact information referencing a patient that does not exist', function () {
        DB::table('patient_contact_information')->insert(validContactInformationAttributes(999_999));
    })->throws(QueryException::class);

    it('rejects contact information referencing a neighborhood that does not exist', function () {
        $patient = Patient::factory()->create();

        DB::table('patient_contact_information')->insert(
            validContactInformationAttributes($patient->id, ['neighborhood_id' => 999_999])
        );
    })->throws(QueryException::class);

    it('rejects a duplicate institutional email that differs only by case', function () {
        $first = Patient::factory()->create();
        $second = Patient::factory()->create();

        DB::table('patient_contact_information')->insert(
            validContactInformationAttributes($first->id, ['institutional_email' => 'User@Example.com'])
        );
        DB::table('patient_contact_information')->insert(
            validContactInformationAttributes($second->id, [
                'personal_email' => 'other@example.com',
                'institutional_email' => 'user@example.com',
            ])
        );
    })->throws(QueryException::class);

    it('allows two rows with a null institutional email', function () {
        $first = Patient::factory()->create();
        $second = Patient::factory()->create();

        DB::table('patient_contact_information')->insert(validContactInformationAttributes($first->id));
        DB::table('patient_contact_information')->insert(
            validContactInformationAttributes($second->id, ['personal_email' => 'other@example.com'])
        );

        expect(DB::table('patient_contact_information')->count())->toBe(2);
    });

    it('allows a valid contact information row', function () {
        $patient = Patient::factory()->create();
        $neighborhood = Neighborhood::factory()->create();

        DB::table('patient_contact_information')->insert(
            validContactInformationAttributes($patient->id, [
                'institutional_email' => 'user@example.com',
                'neighborhood_id' => $neighborhood->id,
            ])
        );

        expect(DB::table('patient_contact_information')->count())->toBe(1);
    });
});

describe('patient emergency contact constraints', function () {
    it('rejects a phone number that is not E.164 format', function () {
        $patient = Patient::factory()->create();

        DB::table('patient_emergency_contacts')->insert(
            validEmergencyContactAttributes($patient->id, ['phone_number' => '1234567890'])
        );
    })->throws(QueryException::class);

    it('rejects a kinship_type value outside the approved domain', function () {
        $patient = Patient::factory()->create();

        DB::table('patient_emergency_contacts')->insert(
            validEmergencyContactAttributes($patient->id, ['kinship_type' => 9])
        );
    })->throws(QueryException::class);

    it('rejects an emergency contact referencing a patient that does not exist', function () {
        DB::table('patient_emergency_contacts')->insert(validEmergencyContactAttributes(999_999));
    })->throws(QueryException::class);

    it('rejects a duplicate phone number for the same patient', function () {
        $patient = Patient::factory()->create();

        DB::table('patient_emergency_contacts')->insert(validEmergencyContactAttributes($patient->id));
        DB::table('patient_emergency_contacts')->insert(
            validEmergencyContactAttributes($patient->id, ['name' => 'John Doe'])
        );
    })->throws(QueryException::class);

    it('allows the same phone number for two different patients', function () {
        $first = Patient::factory()->create();
        $second = Patient::factory()->create();

        DB::table('patient_emergency_contacts')->insert(validEmergencyContactAttributes($first->id));
        DB::table('patient_emergency_contacts')->insert(validEmergencyContactAttributes($second->id));

        expect(DB::table('patient_emergency_contacts')->count())->toBe(2);
    });

    it('allows multiple emergency contacts for the same patient because the relation is one-to-many', function () {
        $patient = Patient::factory()->create();

        DB::table('patient_emergency_contacts')->insert(
            validEmergencyContactAttributes($patient->id, ['phone_number' => '+11234567891'])
        );
        DB::table('patient_emergency_contacts')->insert(
            validEmergencyContactAttributes($patient->id, ['phone_number' => '+11234567892'])
        );
        DB::table('patient_emergency_contacts')->insert(
            validEmergencyContactAttributes($patient->id, ['phone_number' => '+11234567893'])
        );

        expect(DB::table('patient_emergency_contacts')->where('patient_id', $patient->id)->count())->toBe(3);
    });
});
