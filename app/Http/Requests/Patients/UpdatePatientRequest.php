<?php

namespace App\Http\Requests\Patients;

use App\Models\Patient;
use Illuminate\Foundation\Http\Attributes\FailOnUnknownFields;
use Illuminate\Validation\Rule;

/**
 * Staff edit of a whole patient aggregate. Adds the uniqueness rules to the
 * shared aggregate rules, ignoring the patient's own rows so an unchanged
 * value never collides with itself. Staff get detailed field errors; the
 * database constraints still decide races (see `PatientWriter::update`).
 */
#[FailOnUnknownFields]
class UpdatePatientRequest extends PatientAggregateRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $patient = $this->patient();
        $rules = parent::rules();

        $rules['patient.social_security_number'][] = Rule::unique('patients', 'social_security_number')
            ->ignore($patient->getKey());

        $rules['patient.enrollment_number'][] = Rule::unique('patients', 'enrollment_number')
            ->where('enrollment_id', $this->input('patient.enrollment_id'))
            ->ignore($patient->getKey());

        $rules['contact_information.institutional_email'][] = Rule::unique('patient_contact_information', 'institutional_email')
            ->ignore($patient->getKey(), 'patient_id');

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'patient.social_security_number.unique' => __('modules/patients/management.duplicates.social_security_number'),
            'patient.enrollment_number.unique' => __('modules/patients/management.duplicates.enrollment_number'),
            'contact_information.institutional_email.unique' => __('modules/patients/management.duplicates.institutional_email'),
        ];
    }

    private function patient(): Patient
    {
        /** @var Patient */
        return $this->route('patient');
    }
}
