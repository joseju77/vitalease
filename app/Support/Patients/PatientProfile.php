<?php

namespace App\Support\Patients;

use App\Enums\Permission;
use App\Models\Patient;
use App\Models\PatientAilment;
use App\Models\PatientEmergencyContact;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Builds the read-only patient profile shown on the consultation pages and the
 * patient detail page.
 *
 * Sensitive contact data (social security number, emails and every phone
 * number) only reaches viewers holding `patients.view`; for anyone else those
 * fields are `null` while the identifying and clinical fields remain.
 */
final class PatientProfile
{
    /**
     * Every relation is eager loaded in one pass (already-loaded relations are
     * skipped), enum values are sent as raw integers for the frontend to
     * label, and dates use `Y-m-d`.
     *
     * @return array<string, mixed>
     */
    public static function for(Patient $patient, User $viewer): array
    {
        $patient->loadMissing([
            'enrollment',
            'familyMedicalUnit',
            'contactInformation.neighborhood.zipCode.municipality',
            'emergencyContacts' => fn (HasMany $query) => $query->orderBy('id'),
            'ailments' => fn (HasMany $query) => $query->orderBy('ailment_type'),
            'otherAilments',
            'gynecologicalHistory',
        ]);

        $canSeeSensitive = $viewer->can(Permission::PatientsView->value);
        $sensitive = fn (?string $value): ?string => $canSeeSensitive ? $value : null;

        $contactInformation = $patient->contactInformation;
        $neighborhood = $contactInformation?->neighborhood;
        $otherAilments = $patient->otherAilments;
        $gynecologicalHistory = $patient->gynecologicalHistory;

        return [
            'first_name' => $patient->first_name,
            'last_name' => $patient->last_name,
            'second_last_name' => $patient->second_last_name,
            'birth_date' => $patient->birth_date->format('Y-m-d'),
            'age' => $patient->birth_date->age,
            'sex_at_birth' => $patient->sex_at_birth->value,
            'marital_status' => $patient->marital_status->value,
            'blood_type' => $patient->blood_type->value,
            'enrollment' => $patient->enrollment?->name,
            'enrollment_number' => $patient->enrollment_number,
            'external_enrollment' => $patient->external_enrollment,
            'family_medical_unit' => $patient->familyMedicalUnit?->only(['name', 'address']),
            'other_family_medical_unit' => $patient->other_family_medical_unit,
            'social_security_number' => $sensitive($patient->social_security_number),
            'contact_information' => $contactInformation ? [
                'address' => $contactInformation->address,
                'phone_number' => $sensitive($contactInformation->phone_number),
                'personal_email' => $sensitive($contactInformation->personal_email),
                'institutional_email' => $sensitive($contactInformation->institutional_email),
                'neighborhood' => $neighborhood?->name,
                'zip_code' => $neighborhood?->zip_code,
                'municipality' => $neighborhood?->zipCode?->municipality?->name,
            ] : null,
            'emergency_contacts' => $patient->emergencyContacts
                ->map(fn (PatientEmergencyContact $contact): array => [
                    'name' => $contact->name,
                    'phone_number' => $sensitive($contact->phone_number),
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
            'other_ailments' => $otherAilments?->only(['surgeries', 'allergies', 'others']),
            'gynecological_history' => $gynecologicalHistory ? [
                ...$gynecologicalHistory->only([
                    'menarche', 'has_cramps', 'is_cycle_regular', 'cycle_intensity', 'cycle_duration',
                    'cycle_flow_level', 'sexual_activity_start_age', 'last_pap_smear_was_positive',
                    'pregnancies', 'vaginal_deliveries', 'cesareans', 'abortions',
                ]),
                'last_cycle_date' => $gynecologicalHistory->last_cycle_date->format('Y-m-d'),
                'contraceptive_method' => $gynecologicalHistory->contraceptive_method?->value,
                'last_pap_smear_date' => $gynecologicalHistory->last_pap_smear_date?->format('Y-m-d'),
            ] : null,
        ];
    }
}
