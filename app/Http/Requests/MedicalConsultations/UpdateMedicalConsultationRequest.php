<?php

namespace App\Http\Requests\MedicalConsultations;

use App\Enums\MedicalClassification;
use App\Enums\MedicalState;
use App\Enums\TransferType;
use Illuminate\Foundation\Http\Attributes\FailOnUnknownFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

#[FailOnUnknownFields]
class UpdateMedicalConsultationRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * Same aggregate as {@see StoreMedicalConsultationRequest} minus
     * `patient_uuid`: the patient a consultation belongs to never changes on
     * update, so it is not an accepted key here at all — `#[FailOnUnknownFields]`
     * rejects it if sent.
     *
     * `treatment.*.medication_uuid` accepts an active medication OR a
     * medication already linked to this consultation's existing treatment
     * lines, so a line prescribed before its medication was deactivated
     * stays editable (e.g. to adjust its quantity) without being forced out.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $existingMedicationIds = $this->route('consultation')->treatments()->pluck('medication_id')->all();

        return [
            'consultation' => ['required', 'array'],
            'consultation.current_condition' => ['required', 'string', 'max:1024'],
            'consultation.diagnosis' => ['required', 'string', 'max:1024'],

            'condition' => ['required', 'integer', Rule::enum(MedicalState::class)],
            'prognosis' => ['required', 'integer', Rule::enum(MedicalState::class)],
            'medical_classification' => ['required', 'integer', Rule::enum(MedicalClassification::class)],

            'treatment' => ['present', 'array', 'max:20'],
            'treatment.*.medication_uuid' => [
                'required',
                'uuid',
                'distinct',
                Rule::exists('medications', 'uuid')->where(
                    fn ($query) => $query->where('is_active', true)->orWhereIn('id', $existingMedicationIds)
                ),
            ],
            'treatment.*.quantity_dispensed' => ['required', 'integer', 'min:1', 'max:9999'],
            'treatment.*.dose' => ['required', 'string', 'max:255'],
            'treatment.*.frequency' => ['required', 'string', 'max:255'],
            'treatment.*.duration' => ['required', 'string', 'max:255'],

            'vital_signs' => ['required', 'array'],
            'vital_signs.weight' => ['required', 'numeric', 'between:0.5,500', 'decimal:0,2'],
            'vital_signs.height' => ['required', 'numeric', 'between:0.30,2.50', 'decimal:0,2'],
            'vital_signs.blood_pressure_systolic' => ['required', 'integer', 'between:40,300'],
            'vital_signs.blood_pressure_diastolic' => ['required', 'integer', 'between:20,200'],
            'vital_signs.heart_rate' => ['required', 'integer', 'between:20,300'],
            'vital_signs.respiratory_rate' => ['required', 'integer', 'between:4,80'],
            'vital_signs.temperature' => ['required', 'numeric', 'between:30,45', 'decimal:0,1'],
            'vital_signs.oxygen_saturation' => ['required', 'integer', 'between:0,100'],
            'vital_signs.glasgow' => ['required', 'integer', 'between:3,15'],
            'vital_signs.glucose' => ['nullable', 'integer', 'between:10,2000'],

            'physical_examination' => ['required', 'array'],
            'physical_examination.neurological' => ['required', 'string', 'max:512'],
            'physical_examination.head_neck' => ['required', 'string', 'max:512'],
            'physical_examination.thorax_cardiopulmonary' => ['required', 'string', 'max:512'],
            'physical_examination.abdomen' => ['required', 'string', 'max:512'],
            'physical_examination.extremities' => ['required', 'string', 'max:512'],
            'physical_examination.cabinet_laboratory' => ['required', 'string', 'max:512'],

            'regulation' => ['nullable', 'array'],
            'regulation.transfer_type' => ['required_with:regulation', 'integer', Rule::enum(TransferType::class)],
            'regulation.regulated_at' => ['required_with:regulation', 'date'],
            'regulation.ambulance_registration' => ['nullable', 'string', 'max:255'],
            'regulation.regulation_number' => ['nullable', 'string', 'max:255'],
            'regulation.clinic_id' => ['nullable', 'string', 'max:255'],
            'regulation.receiver_physician' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Get the "after" validation callables for the request.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            $this->validateDiastolicLessThanSystolic(...),
        ];
    }

    /**
     * Reject vital signs where the diastolic reading is not strictly below
     * the systolic reading.
     */
    private function validateDiastolicLessThanSystolic(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['vital_signs.blood_pressure_systolic', 'vital_signs.blood_pressure_diastolic'])) {
            return;
        }

        $systolic = $this->input('vital_signs.blood_pressure_systolic');
        $diastolic = $this->input('vital_signs.blood_pressure_diastolic');

        if ($diastolic >= $systolic) {
            $validator->errors()->add(
                'vital_signs.blood_pressure_diastolic',
                __('modules/consultations/management.custom.diastolic_not_less_than_systolic')
            );
        }
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return __('modules/consultations/management.attributes');
    }

    /**
     * Get custom error messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'treatment.*.medication_uuid.distinct' => __('modules/consultations/management.custom.duplicate_medication'),
            'treatment.*.medication_uuid.exists' => __('modules/consultations/management.custom.inactive_medication'),
        ];
    }
}
