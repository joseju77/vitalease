<?php

namespace App\Support\Patients;

use App\Models\Patient;
use App\Models\PatientAilment;
use App\Models\PatientEmergencyContact;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The prefilled edit form: the stored aggregate in the same shape the
 * registration form submits (raw enum values, `Y-m-d` dates, identifiers of
 * catalog rows), so the edit page can reuse the registration step components.
 * Only for staff allowed to update the patient: it carries every sensitive
 * field in the clear.
 */
final class PatientEditPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Patient $patient): array
    {
        $patient->loadMissing([
            'contactInformation.neighborhood',
            'emergencyContacts' => fn (HasMany $query) => $query->orderBy('id'),
            'ailments' => fn (HasMany $query) => $query->orderBy('ailment_type'),
            'otherAilments',
            'gynecologicalHistory',
        ]);

        $contactInformation = $patient->contactInformation;
        $otherAilments = $patient->otherAilments;
        $gynecologicalHistory = $patient->gynecologicalHistory;

        return [
            'patient' => [
                'first_name' => $patient->first_name,
                'last_name' => $patient->last_name,
                'second_last_name' => $patient->second_last_name,
                'birth_date' => $patient->birth_date->format('Y-m-d'),
                'sex_at_birth' => $patient->sex_at_birth->value,
                'marital_status' => $patient->marital_status->value,
                'blood_type' => $patient->blood_type->value,
                'enrollment_id' => $patient->enrollment_id,
                'enrollment_number' => $patient->enrollment_number,
                'external_enrollment' => $patient->external_enrollment,
                'family_medical_unit_id' => $patient->family_medical_unit_id,
                'other_family_medical_unit' => $patient->other_family_medical_unit,
                'social_security_number' => $patient->social_security_number,
            ],
            'contact_information' => [
                'address' => $contactInformation?->address,
                'phone_number' => $contactInformation?->phone_number,
                'personal_email' => $contactInformation?->personal_email,
                'institutional_email' => $contactInformation?->institutional_email,
                'zip_code' => $contactInformation?->neighborhood?->zip_code,
                'neighborhood_id' => $contactInformation?->neighborhood_id,
            ],
            'emergency_contacts' => $patient->emergencyContacts
                ->map(fn (PatientEmergencyContact $contact): array => [
                    'name' => $contact->name,
                    'phone_number' => $contact->phone_number,
                    'kinship_type' => $contact->kinship_type->value,
                ])
                ->all(),
            'ailments' => $patient->ailments
                ->map(fn (PatientAilment $ailment): array => [
                    'ailment_type' => $ailment->ailment_type->value,
                    'diagnosed_at' => $ailment->diagnosed_at->format('Y-m-d'),
                    'treatment_notes' => $ailment->treatment_notes,
                ])
                ->all(),
            'other_ailments' => $otherAilments === null ? null : [
                'surgeries' => $otherAilments->surgeries,
                'allergies' => $otherAilments->allergies,
                'others' => $otherAilments->others,
            ],
            'gynecological_history' => $gynecologicalHistory === null ? null : [
                'menarche' => $gynecologicalHistory->menarche,
                'has_cramps' => $gynecologicalHistory->has_cramps,
                'is_cycle_regular' => $gynecologicalHistory->is_cycle_regular,
                'cycle_intensity' => $gynecologicalHistory->cycle_intensity,
                'cycle_duration' => $gynecologicalHistory->cycle_duration,
                'cycle_flow_level' => $gynecologicalHistory->cycle_flow_level,
                'last_cycle_date' => $gynecologicalHistory->last_cycle_date->format('Y-m-d'),
                'sexual_activity_start_age' => $gynecologicalHistory->sexual_activity_start_age,
                'contraceptive_method' => $gynecologicalHistory->contraceptive_method?->value,
                'last_pap_smear_date' => $gynecologicalHistory->last_pap_smear_date?->format('Y-m-d'),
                'last_pap_smear_was_positive' => $gynecologicalHistory->last_pap_smear_was_positive,
                'pregnancies' => $gynecologicalHistory->pregnancies,
                'vaginal_deliveries' => $gynecologicalHistory->vaginal_deliveries,
                'cesareans' => $gynecologicalHistory->cesareans,
                'abortions' => $gynecologicalHistory->abortions,
            ],
        ];
    }
}
