<?php

namespace App\Http\Controllers;

use App\Enums\MedicalClassification;
use App\Enums\MedicalState;
use App\Enums\TransferType;
use App\Http\Requests\MedicalConsultations\StoreMedicalConsultationRequest;
use App\Http\Requests\MedicalConsultations\UpdateMedicalConsultationRequest;
use App\Models\MedicalConsultation;
use App\Models\Patient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class MedicalConsultationController extends Controller
{
    public function create(Request $request): Response
    {
        $patientUuid = $request->query('patient');
        abort_unless(is_string($patientUuid) && Str::isUuid($patientUuid), 422);

        $patient = Patient::query()->where('uuid', $patientUuid)->firstOrFail();

        return Inertia::render('consultations/Create', [
            'patient' => $this->mapPatient($patient),
            ...$this->formOptions(),
        ]);
    }

    public function show(Request $request, MedicalConsultation $consultation): Response
    {
        $consultation->load(['patient', 'physician', 'vitalSigns', 'physicalExamination', 'regulation']);

        return Inertia::render('consultations/Show', [
            'consultation' => $this->mapConsultationAggregate($consultation, $request),
        ]);
    }

    public function edit(Request $request, MedicalConsultation $consultation): Response
    {
        $consultation->load(['patient', 'physician', 'vitalSigns', 'physicalExamination', 'regulation']);

        return Inertia::render('consultations/Edit', [
            'consultation' => $this->mapConsultationAggregate($consultation, $request),
            ...$this->formOptions(),
        ]);
    }

    /**
     * Create a medical consultation together with its vital signs, physical
     * examination, and optional regulation, all inside one transaction.
     *
     * The authenticated user becomes the consultation's physician; the code
     * is generated server-side by the model's `creating` hook.
     */
    public function store(StoreMedicalConsultationRequest $request): RedirectResponse
    {
        $consultation = DB::transaction(function () use ($request): MedicalConsultation {
            $patient = Patient::query()->where('uuid', $request->validated('patient_uuid'))->firstOrFail();

            $consultation = MedicalConsultation::query()->create([
                'current_condition' => $request->validated('consultation.current_condition'),
                'diagnosis' => $request->validated('consultation.diagnosis'),
                'condition' => $request->validated('condition'),
                'prognosis' => $request->validated('prognosis'),
                'treatment' => $request->validated('treatment'),
                'medical_classification' => $request->validated('medical_classification'),
                'physician_id' => $request->user()->id,
                'patient_id' => $patient->id,
            ]);

            $consultation->vitalSigns()->create($request->validated('vital_signs'));
            $consultation->physicalExamination()->create($request->validated('physical_examination'));

            $regulation = $request->validated('regulation');
            if ($regulation !== null) {
                $consultation->regulation()->create($regulation);
            }

            return $consultation;
        });

        $this->flashConsultation($consultation, 'created');

        return redirect()->route('dashboard.index');
    }

    /**
     * Update the consultation aggregate atomically.
     *
     * The patient, code, and physician never change on update, regardless of
     * the payload: those keys are not even accepted by the request.
     */
    public function update(UpdateMedicalConsultationRequest $request, MedicalConsultation $consultation): RedirectResponse
    {
        DB::transaction(function () use ($request, $consultation): void {
            $consultation->update([
                'current_condition' => $request->validated('consultation.current_condition'),
                'diagnosis' => $request->validated('consultation.diagnosis'),
                'condition' => $request->validated('condition'),
                'prognosis' => $request->validated('prognosis'),
                'treatment' => $request->validated('treatment'),
                'medical_classification' => $request->validated('medical_classification'),
            ]);

            $consultation->vitalSigns->update($request->validated('vital_signs'));
            $consultation->physicalExamination->update($request->validated('physical_examination'));

            $regulation = $request->validated('regulation');
            if ($regulation === null) {
                $consultation->regulation()->delete();
            } else {
                $consultation->regulation()->updateOrCreate([], $regulation);
            }
        });

        $this->flashConsultation($consultation, 'updated');

        return redirect()->route('dashboard.index');
    }

    /**
     * Hard-delete the consultation; child rows cascade at the database
     * level.
     */
    public function destroy(MedicalConsultation $consultation): RedirectResponse
    {
        $this->flashConsultation($consultation, 'deleted');

        DB::transaction(function () use ($consultation): void {
            $consultation->delete();
        });

        return redirect()->route('dashboard.index');
    }

    /** @return array<string, list<int>> */
    private function formOptions(): array
    {
        return [
            'medicalStateOptions' => array_column(MedicalState::cases(), 'value'),
            'medicalClassificationOptions' => array_column(MedicalClassification::cases(), 'value'),
            'transferTypeOptions' => array_column(TransferType::cases(), 'value'),
        ];
    }

    /** @return array{uuid: string, full_name: string, enrollment_number: ?string} */
    private function mapPatient(Patient $patient): array
    {
        return [
            'uuid' => $patient->uuid,
            'full_name' => trim(implode(' ', array_filter([$patient->first_name, $patient->last_name, $patient->second_last_name]))),
            'enrollment_number' => $patient->enrollment_number,
        ];
    }

    /** @return array<string, mixed> */
    private function mapConsultationAggregate(MedicalConsultation $consultation, Request $request): array
    {
        $regulation = $consultation->regulation;

        return [
            'uuid' => $consultation->uuid,
            'code' => $consultation->code,
            'created_at' => $consultation->created_at->toIso8601String(),
            'patient' => $this->mapPatient($consultation->patient),
            'physician' => ['name' => $consultation->physician->name],
            'consultation' => [
                'current_condition' => $consultation->current_condition,
                'diagnosis' => $consultation->diagnosis,
            ],
            'condition' => $consultation->condition->value,
            'prognosis' => $consultation->prognosis->value,
            'medical_classification' => $consultation->medical_classification->value,
            'treatment' => $consultation->treatment ?? [],
            'vital_signs' => $consultation->vitalSigns->only([
                'weight', 'height', 'blood_pressure_systolic', 'blood_pressure_diastolic',
                'heart_rate', 'respiratory_rate', 'temperature', 'oxygen_saturation', 'glasgow', 'glucose',
            ]),
            'physical_examination' => $consultation->physicalExamination->only([
                'neurological', 'head_neck', 'thorax_cardiopulmonary', 'abdomen', 'extremities', 'cabinet_laboratory',
            ]),
            'regulation' => $regulation ? [
                'transfer_type' => $regulation->transfer_type->value,
                'regulated_at' => $regulation->regulated_at->format('Y-m-d\TH:i'),
                'ambulance_registration' => $regulation->ambulance_registration,
                'regulation_number' => $regulation->regulation_number,
                'clinic_id' => $regulation->clinic_id,
                'receiver_physician' => $regulation->receiver_physician,
            ] : null,
            'can' => [
                'update' => $request->user()->can('update', $consultation),
                'delete' => $request->user()->can('delete', $consultation),
            ],
        ];
    }

    private function flashConsultation(MedicalConsultation $consultation, string $action): void
    {
        Inertia::flash('consultation', [
            'uuid' => $consultation->uuid,
            'code' => $consultation->code,
            'action' => $action,
        ]);
    }
}
