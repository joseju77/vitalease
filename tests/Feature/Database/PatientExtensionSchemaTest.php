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

function validAilmentAttributes(int $patientId, array $overrides = []): array
{
    return array_merge([
        'patient_id' => $patientId,
        'ailment_type' => 1,
        'diagnosed_at' => '2020-01-01',
        'treatment_notes' => null,
    ], $overrides);
}

function validOtherAilmentAttributes(int $patientId, array $overrides = []): array
{
    return array_merge([
        'patient_id' => $patientId,
        'surgeries' => 'Appendectomy',
        'allergies' => null,
        'others' => null,
    ], $overrides);
}

function validGynecologicalHistoryAttributes(int $patientId, array $overrides = []): array
{
    return array_merge([
        'patient_id' => $patientId,
        'menarche' => 12,
        'has_cramps' => true,
        'is_cycle_regular' => true,
        'cycle_intensity' => 5,
        'cycle_duration' => 5,
        'cycle_flow_level' => 3,
        'last_cycle_date' => '2024-01-01',
        'sexual_activity_start_age' => null,
        'contraceptive_method' => null,
        'last_pap_smear_date' => null,
        'last_pap_smear_was_positive' => null,
        'pregnancies' => 2,
        'vaginal_deliveries' => 1,
        'cesareans' => 1,
        'abortions' => 0,
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

describe('patient ailment constraints', function () {
    it('rejects an ailment_type value outside the approved domain', function () {
        $patient = Patient::factory()->create();

        DB::table('patient_ailments')->insert(
            validAilmentAttributes($patient->id, ['ailment_type' => 7])
        );
    })->throws(QueryException::class);

    it('rejects an ailment referencing a patient that does not exist', function () {
        DB::table('patient_ailments')->insert(validAilmentAttributes(999_999));
    })->throws(QueryException::class);

    it('rejects a duplicate (patient_id, ailment_type) pair', function () {
        $patient = Patient::factory()->create();

        DB::table('patient_ailments')->insert(validAilmentAttributes($patient->id));
        DB::table('patient_ailments')->insert(
            validAilmentAttributes($patient->id, ['diagnosed_at' => '2021-01-01'])
        );
    })->throws(QueryException::class);

    it('allows the same ailment_type for two different patients', function () {
        $first = Patient::factory()->create();
        $second = Patient::factory()->create();

        DB::table('patient_ailments')->insert(validAilmentAttributes($first->id));
        DB::table('patient_ailments')->insert(validAilmentAttributes($second->id));

        expect(DB::table('patient_ailments')->count())->toBe(2);
    });

    it('allows multiple different ailment types for the same patient because the relation is one-to-many', function () {
        $patient = Patient::factory()->create();

        DB::table('patient_ailments')->insert(validAilmentAttributes($patient->id, ['ailment_type' => 1]));
        DB::table('patient_ailments')->insert(validAilmentAttributes($patient->id, ['ailment_type' => 2]));

        expect(DB::table('patient_ailments')->where('patient_id', $patient->id)->count())->toBe(2);
    });

    it('allows a valid ailment row', function () {
        $patient = Patient::factory()->create();

        DB::table('patient_ailments')->insert(
            validAilmentAttributes($patient->id, ['treatment_notes' => 'Managed with medication'])
        );

        expect(DB::table('patient_ailments')->count())->toBe(1);
    });
});

describe('patient other ailment constraints', function () {
    it('rejects a row where surgeries, allergies, and others are all null', function () {
        $patient = Patient::factory()->create();

        DB::table('patient_other_ailments')->insert(
            validOtherAilmentAttributes($patient->id, ['surgeries' => null])
        );
    })->throws(QueryException::class);

    it('rejects a duplicate patient_id because the relation is one-to-one', function () {
        $patient = Patient::factory()->create();

        DB::table('patient_other_ailments')->insert(validOtherAilmentAttributes($patient->id));
        DB::table('patient_other_ailments')->insert(
            validOtherAilmentAttributes($patient->id, ['allergies' => 'Penicillin'])
        );
    })->throws(QueryException::class);

    it('rejects other ailments referencing a patient that does not exist', function () {
        DB::table('patient_other_ailments')->insert(validOtherAilmentAttributes(999_999));
    })->throws(QueryException::class);

    it('allows a row with only one non-null field', function () {
        $patient = Patient::factory()->create();

        DB::table('patient_other_ailments')->insert(
            validOtherAilmentAttributes($patient->id, ['surgeries' => null, 'allergies' => 'Penicillin'])
        );

        expect(DB::table('patient_other_ailments')->count())->toBe(1);
    });

    it('allows a valid row with every field filled', function () {
        $patient = Patient::factory()->create();

        DB::table('patient_other_ailments')->insert(
            validOtherAilmentAttributes($patient->id, ['allergies' => 'Penicillin', 'others' => 'None'])
        );

        expect(DB::table('patient_other_ailments')->count())->toBe(1);
    });
});

describe('patient gynecological history constraints', function () {
    it('rejects a pap smear date without a pap smear result', function () {
        $patient = Patient::factory()->create();

        DB::table('patient_gynecological_history')->insert(
            validGynecologicalHistoryAttributes($patient->id, ['last_pap_smear_date' => '2023-01-01'])
        );
    })->throws(QueryException::class);

    it('rejects a pap smear result without a pap smear date', function () {
        $patient = Patient::factory()->create();

        DB::table('patient_gynecological_history')->insert(
            validGynecologicalHistoryAttributes($patient->id, ['last_pap_smear_was_positive' => false])
        );
    })->throws(QueryException::class);

    it('allows both pap smear fields to be null', function () {
        $patient = Patient::factory()->create();

        DB::table('patient_gynecological_history')->insert(validGynecologicalHistoryAttributes($patient->id));

        expect(DB::table('patient_gynecological_history')->count())->toBe(1);
    });

    it('allows both pap smear fields to be set together', function () {
        $patient = Patient::factory()->create();

        DB::table('patient_gynecological_history')->insert(
            validGynecologicalHistoryAttributes($patient->id, [
                'last_pap_smear_date' => '2023-01-01',
                'last_pap_smear_was_positive' => false,
            ])
        );

        expect(DB::table('patient_gynecological_history')->count())->toBe(1);
    });

    it('rejects delivery counts that exceed pregnancies', function () {
        $patient = Patient::factory()->create();

        DB::table('patient_gynecological_history')->insert(
            validGynecologicalHistoryAttributes($patient->id, [
                'pregnancies' => 2,
                'vaginal_deliveries' => 1,
                'cesareans' => 1,
                'abortions' => 1,
            ])
        );
    })->throws(QueryException::class);

    it('allows delivery counts exactly equal to pregnancies', function () {
        $patient = Patient::factory()->create();

        DB::table('patient_gynecological_history')->insert(
            validGynecologicalHistoryAttributes($patient->id, [
                'pregnancies' => 2,
                'vaginal_deliveries' => 1,
                'cesareans' => 1,
                'abortions' => 0,
            ])
        );

        expect(DB::table('patient_gynecological_history')->count())->toBe(1);
    });

    it('rejects a negative pregnancies value', function () {
        $patient = Patient::factory()->create();

        DB::table('patient_gynecological_history')->insert(
            validGynecologicalHistoryAttributes($patient->id, ['pregnancies' => -1, 'vaginal_deliveries' => 0, 'cesareans' => 0, 'abortions' => 0])
        );
    })->throws(QueryException::class);

    it('rejects a negative vaginal_deliveries value', function () {
        $patient = Patient::factory()->create();

        DB::table('patient_gynecological_history')->insert(
            validGynecologicalHistoryAttributes($patient->id, ['vaginal_deliveries' => -1])
        );
    })->throws(QueryException::class);

    it('rejects a negative cesareans value', function () {
        $patient = Patient::factory()->create();

        DB::table('patient_gynecological_history')->insert(
            validGynecologicalHistoryAttributes($patient->id, ['cesareans' => -1])
        );
    })->throws(QueryException::class);

    it('rejects a negative abortions value', function () {
        $patient = Patient::factory()->create();

        DB::table('patient_gynecological_history')->insert(
            validGynecologicalHistoryAttributes($patient->id, ['abortions' => -1])
        );
    })->throws(QueryException::class);

    it('rejects a non-positive menarche value', function () {
        $patient = Patient::factory()->create();

        DB::table('patient_gynecological_history')->insert(
            validGynecologicalHistoryAttributes($patient->id, ['menarche' => 0])
        );
    })->throws(QueryException::class);

    it('rejects a cycle_intensity value outside the 1-10 domain', function () {
        $patient = Patient::factory()->create();

        DB::table('patient_gynecological_history')->insert(
            validGynecologicalHistoryAttributes($patient->id, ['cycle_intensity' => 11])
        );
    })->throws(QueryException::class);

    it('rejects a non-positive cycle_duration value', function () {
        $patient = Patient::factory()->create();

        DB::table('patient_gynecological_history')->insert(
            validGynecologicalHistoryAttributes($patient->id, ['cycle_duration' => 0])
        );
    })->throws(QueryException::class);

    it('rejects a non-positive cycle_flow_level value', function () {
        $patient = Patient::factory()->create();

        DB::table('patient_gynecological_history')->insert(
            validGynecologicalHistoryAttributes($patient->id, ['cycle_flow_level' => 0])
        );
    })->throws(QueryException::class);

    it('rejects a contraceptive_method value outside the approved domain', function () {
        $patient = Patient::factory()->create();

        DB::table('patient_gynecological_history')->insert(
            validGynecologicalHistoryAttributes($patient->id, ['contraceptive_method' => 12])
        );
    })->throws(QueryException::class);

    it('rejects a duplicate patient_id because the relation is one-to-one', function () {
        $patient = Patient::factory()->create();

        DB::table('patient_gynecological_history')->insert(validGynecologicalHistoryAttributes($patient->id));
        DB::table('patient_gynecological_history')->insert(validGynecologicalHistoryAttributes($patient->id));
    })->throws(QueryException::class);

    it('rejects gynecological history referencing a patient that does not exist', function () {
        DB::table('patient_gynecological_history')->insert(validGynecologicalHistoryAttributes(999_999));
    })->throws(QueryException::class);

    it('allows a valid gynecological history row', function () {
        $patient = Patient::factory()->create();

        DB::table('patient_gynecological_history')->insert(
            validGynecologicalHistoryAttributes($patient->id, [
                'sexual_activity_start_age' => 18,
                'contraceptive_method' => 3,
            ])
        );

        expect(DB::table('patient_gynecological_history')->count())->toBe(1);
    });
});
