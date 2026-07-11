<?php

namespace App\Http\Requests\Patients;

use App\Enums\AilmentType;
use App\Enums\BloodType;
use App\Enums\ContraceptiveMethod;
use App\Enums\KinshipType;
use App\Enums\MaritalStatus;
use App\Enums\SexAtBirth;
use App\Models\Neighborhood;
use Illuminate\Foundation\Http\Attributes\FailOnUnknownFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

#[FailOnUnknownFields]
class StorePatientRegistrationRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * Every nested key the aggregate accepts must be declared here: this
     * class doubles as the allow-list `#[FailOnUnknownFields]` uses to
     * reject unexpected or privileged keys at any nesting level.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'patient' => ['required', 'array'],
            'patient.first_name' => ['required', 'string', 'max:255'],
            'patient.last_name' => ['required', 'string', 'max:255'],
            'patient.second_last_name' => ['nullable', 'string', 'max:255'],
            'patient.birth_date' => ['required', 'date'],
            'patient.sex_at_birth' => ['required', 'integer', Rule::enum(SexAtBirth::class)],
            'patient.marital_status' => ['required', 'integer', Rule::enum(MaritalStatus::class)],
            'patient.blood_type' => ['required', 'integer', Rule::enum(BloodType::class)],
            'patient.enrollment_id' => ['nullable', 'integer', Rule::exists('enrollments', 'id')],
            'patient.enrollment_number' => ['nullable', 'string', 'max:255'],
            'patient.external_enrollment' => ['nullable', 'string', 'max:255'],
            'patient.family_medical_unit_id' => ['nullable', 'integer', Rule::exists('family_medical_units', 'id')],
            'patient.other_family_medical_unit' => ['nullable', 'string', 'max:255'],
            'patient.social_security_number' => ['required', 'string', 'regex:/^[0-9]{11}$/'],

            'contact_information' => ['required', 'array'],
            'contact_information.address' => ['required', 'string', 'max:255'],
            'contact_information.phone_number' => ['required', 'string', 'regex:/^\+[1-9]\d{1,14}$/'],
            'contact_information.personal_email' => ['required', 'string', 'email', 'max:255'],
            'contact_information.institutional_email' => ['nullable', 'string', 'email', 'max:255'],
            // `zip_code` is UI-only: it lets the client narrow the neighborhood
            // catalog before submission. It has no column on
            // `patient_contact_information` and is stripped before create().
            'contact_information.zip_code' => ['nullable', 'string', 'regex:/^[0-9]{5}$/', Rule::exists('zip_codes', 'code')],
            'contact_information.neighborhood_id' => ['nullable', 'integer', Rule::exists('neighborhoods', 'id')],

            'emergency_contacts' => ['required', 'array', 'min:1'],
            'emergency_contacts.*.name' => ['required', 'string', 'max:255'],
            'emergency_contacts.*.phone_number' => ['required', 'string', 'regex:/^\+[1-9]\d{1,14}$/', 'distinct'],
            'emergency_contacts.*.kinship_type' => ['required', 'integer', Rule::enum(KinshipType::class)],

            'ailments' => ['present', 'array'],
            'ailments.*.ailment_type' => ['required', 'integer', Rule::enum(AilmentType::class), 'distinct'],
            'ailments.*.diagnosed_at' => ['required', 'date'],
            'ailments.*.treatment_notes' => ['nullable', 'string'],

            'other_ailments' => ['nullable', 'array'],
            'other_ailments.surgeries' => ['nullable', 'string'],
            'other_ailments.allergies' => ['nullable', 'string'],
            'other_ailments.others' => ['nullable', 'string'],

            'gynecological_history' => ['nullable', 'array'],
            'gynecological_history.menarche' => ['required_with:gynecological_history', 'integer', 'min:1'],
            'gynecological_history.has_cramps' => ['required_with:gynecological_history', 'boolean'],
            'gynecological_history.is_cycle_regular' => ['required_with:gynecological_history', 'boolean'],
            'gynecological_history.cycle_intensity' => ['required_with:gynecological_history', 'integer', 'between:1,10'],
            'gynecological_history.cycle_duration' => ['required_with:gynecological_history', 'integer', 'min:1'],
            'gynecological_history.cycle_flow_level' => ['required_with:gynecological_history', 'integer', 'min:1'],
            'gynecological_history.last_cycle_date' => ['required_with:gynecological_history', 'date'],
            'gynecological_history.sexual_activity_start_age' => ['nullable', 'integer', 'min:0'],
            'gynecological_history.contraceptive_method' => ['nullable', 'integer', Rule::enum(ContraceptiveMethod::class)],
            'gynecological_history.last_pap_smear_date' => ['nullable', 'date'],
            'gynecological_history.last_pap_smear_was_positive' => ['nullable', 'boolean'],
            'gynecological_history.pregnancies' => ['required_with:gynecological_history', 'integer', 'min:0'],
            'gynecological_history.vaginal_deliveries' => ['required_with:gynecological_history', 'integer', 'min:0'],
            'gynecological_history.cesareans' => ['required_with:gynecological_history', 'integer', 'min:0'],
            'gynecological_history.abortions' => ['required_with:gynecological_history', 'integer', 'min:0'],
        ];
    }

    /**
     * Get the "after" validation callables for the request.
     *
     * These mirror stage-03a's database CHECK constraints that base rules
     * cannot express alone: the enrollment and family-medical-unit XORs,
     * postal-code/neighborhood pairing, non-empty other-ailment content,
     * female-only gynecological history, paired pap-smear fields, and
     * pregnancy-count arithmetic.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            $this->validateEnrollmentXor(...),
            $this->validateFamilyMedicalUnitXor(...),
            $this->validateNeighborhoodMatchesZipCode(...),
            $this->validateOtherAilmentsNotEmpty(...),
            $this->validateGynecologicalHistoryApplicability(...),
            $this->validatePapSmearPairing(...),
            $this->validatePregnancyCounts(...),
        ];
    }

    /**
     * Mirror `chk_enrollment_consistency`: exactly one of an internal
     * enrollment (id + number) or an external enrollment reference.
     */
    private function validateEnrollmentXor(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['patient.enrollment_id', 'patient.enrollment_number', 'patient.external_enrollment'])) {
            return;
        }

        $enrollmentId = $this->input('patient.enrollment_id');
        $enrollmentNumber = $this->input('patient.enrollment_number');
        $externalEnrollment = $this->input('patient.external_enrollment');

        $internal = $enrollmentId !== null && $enrollmentNumber !== null && $externalEnrollment === null;
        $external = $enrollmentId === null && $enrollmentNumber === null && $externalEnrollment !== null;

        if (! $internal && ! $external) {
            $validator->errors()->add(
                'patient.enrollment_id',
                __('modules/patients/registration.custom.enrollment_xor')
            );
        }
    }

    /**
     * Mirror `chk_family_medical_unit_consistency`: exactly one of a
     * catalog-listed unit or a free-text unit name.
     */
    private function validateFamilyMedicalUnitXor(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['patient.family_medical_unit_id', 'patient.other_family_medical_unit'])) {
            return;
        }

        $familyMedicalUnitId = $this->input('patient.family_medical_unit_id');
        $otherFamilyMedicalUnit = $this->input('patient.other_family_medical_unit');

        $known = $familyMedicalUnitId !== null && $otherFamilyMedicalUnit === null;
        $other = $familyMedicalUnitId === null && $otherFamilyMedicalUnit !== null;

        if (! $known && ! $other) {
            $validator->errors()->add(
                'patient.family_medical_unit_id',
                __('modules/patients/registration.custom.family_medical_unit_xor')
            );
        }
    }

    /**
     * Reject a neighborhood that does not belong to the submitted postal
     * code, when both were supplied.
     */
    private function validateNeighborhoodMatchesZipCode(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['contact_information.zip_code', 'contact_information.neighborhood_id'])) {
            return;
        }

        $zipCode = $this->input('contact_information.zip_code');
        $neighborhoodId = $this->input('contact_information.neighborhood_id');

        if ($zipCode === null || $neighborhoodId === null) {
            return;
        }

        $belongsToZipCode = Neighborhood::query()
            ->whereKey($neighborhoodId)
            ->where('zip_code', $zipCode)
            ->exists();

        if (! $belongsToZipCode) {
            $validator->errors()->add(
                'contact_information.neighborhood_id',
                __('modules/patients/registration.custom.neighborhood_zip_code_mismatch')
            );
        }
    }

    /**
     * Mirror `chk_other_ailments_nonempty`: when `other_ailments` is sent,
     * at least one of its three fields must carry content.
     */
    private function validateOtherAilmentsNotEmpty(Validator $validator): void
    {
        if (! $this->filled('other_ailments')) {
            return;
        }

        $otherAilments = $this->input('other_ailments', []);

        if (array_filter([
            $otherAilments['surgeries'] ?? null,
            $otherAilments['allergies'] ?? null,
            $otherAilments['others'] ?? null,
        ]) === []) {
            $validator->errors()->add(
                'other_ailments',
                __('modules/patients/registration.custom.other_ailments_empty')
            );
        }
    }

    /**
     * Gynecological history is only applicable when sex at birth is Female.
     */
    private function validateGynecologicalHistoryApplicability(Validator $validator): void
    {
        if (! $this->filled('gynecological_history')) {
            return;
        }

        if ($validator->errors()->hasAny(['patient.sex_at_birth'])) {
            return;
        }

        if ($this->input('patient.sex_at_birth') !== SexAtBirth::Female->value) {
            $validator->errors()->add(
                'gynecological_history',
                __('modules/patients/registration.custom.gynecological_history_not_applicable')
            );
        }
    }

    /**
     * Mirror `chk_last_pap_smear_consistency`: the pap-smear date and
     * result must be sent together or not at all.
     */
    private function validatePapSmearPairing(Validator $validator): void
    {
        if (! $this->filled('gynecological_history')) {
            return;
        }

        if ($validator->errors()->hasAny([
            'gynecological_history.last_pap_smear_date',
            'gynecological_history.last_pap_smear_was_positive',
        ])) {
            return;
        }

        $date = $this->input('gynecological_history.last_pap_smear_date');
        $wasPositive = $this->input('gynecological_history.last_pap_smear_was_positive');

        if (($date === null) !== ($wasPositive === null)) {
            $validator->errors()->add(
                'gynecological_history.last_pap_smear_date',
                __('modules/patients/registration.custom.pap_smear_pairing')
            );
        }
    }

    /**
     * Mirror `chk_counts_consistent`: deliveries plus cesareans plus
     * abortions must not exceed pregnancies.
     */
    private function validatePregnancyCounts(Validator $validator): void
    {
        if (! $this->filled('gynecological_history')) {
            return;
        }

        if ($validator->errors()->hasAny([
            'gynecological_history.pregnancies',
            'gynecological_history.vaginal_deliveries',
            'gynecological_history.cesareans',
            'gynecological_history.abortions',
        ])) {
            return;
        }

        $pregnancies = (int) $this->input('gynecological_history.pregnancies');
        $vaginalDeliveries = (int) $this->input('gynecological_history.vaginal_deliveries');
        $cesareans = (int) $this->input('gynecological_history.cesareans');
        $abortions = (int) $this->input('gynecological_history.abortions');

        if ($vaginalDeliveries + $cesareans + $abortions > $pregnancies) {
            $validator->errors()->add(
                'gynecological_history.pregnancies',
                __('modules/patients/registration.custom.pregnancy_counts_exceeded')
            );
        }
    }
}
