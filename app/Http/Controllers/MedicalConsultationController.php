<?php

namespace App\Http\Controllers;

use App\Enums\MedicalClassification;
use App\Enums\MedicalState;
use App\Enums\TransferType;
use App\Http\Requests\MedicalConsultations\RestoreMedicalConsultationRequest;
use App\Http\Requests\MedicalConsultations\StoreMedicalConsultationRequest;
use App\Http\Requests\MedicalConsultations\UpdateMedicalConsultationRequest;
use App\Http\Requests\MedicalConsultations\VoidMedicalConsultationRequest;
use App\Models\MedicalConsultation;
use App\Models\MedicalConsultationTreatment;
use App\Models\Medication;
use App\Models\Patient;
use App\Services\Inventory\InsufficientStockException;
use App\Services\MedicalConsultations\ConsultationHistory;
use App\Services\MedicalConsultations\ConsultationVoiding;
use App\Services\MedicalConsultations\ConsultationWriter;
use App\Support\MedicalConsultationAbilities;
use App\Support\Patients\PatientProfile;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class MedicalConsultationController extends Controller
{
    public function __construct(
        private readonly ConsultationWriter $consultationWriter,
        private readonly ConsultationVoiding $consultationVoiding,
    ) {}

    public function create(Request $request): Response
    {
        $patientUuid = $request->query('patient');
        abort_unless(is_string($patientUuid) && Str::isUuid($patientUuid), 422);

        $patient = Patient::query()->where('uuid', $patientUuid)->firstOrFail();

        return Inertia::render('consultations/Create', [
            'patient' => $this->mapPatient($patient),
            'patientProfile' => PatientProfile::for($patient, $request->user()),
            ...$this->formOptions(),
        ]);
    }

    public function show(Request $request, MedicalConsultation $consultation): Response
    {
        $consultation->load([
            'patient', 'physician', 'voidedBy', 'vitalSigns', 'physicalExamination', 'regulation',
            'treatments' => fn (HasMany $query) => $query->orderBy('id')->with('medication'),
        ]);

        return Inertia::render('consultations/Show', [
            'consultation' => $this->mapConsultationAggregate($consultation, $request),
            'patientProfile' => PatientProfile::for($consultation->patient, $request->user()),
        ]);
    }

    public function edit(Request $request, MedicalConsultation $consultation): Response
    {
        $consultation->load([
            'patient', 'physician', 'vitalSigns', 'physicalExamination', 'regulation',
            'treatments' => fn (HasMany $query) => $query->orderBy('id')->with('medication'),
        ]);

        return Inertia::render('consultations/Edit', [
            'consultation' => $this->mapConsultationAggregate($consultation, $request),
            'patientProfile' => PatientProfile::for($consultation->patient, $request->user()),
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
        $patient = Patient::query()->where('uuid', $request->validated('patient_uuid'))->firstOrFail();

        try {
            $consultation = $this->consultationWriter->create($patient, $request->validated(), $request->user());
        } catch (InsufficientStockException $e) {
            throw $e->toValidationException('treatment.%d.quantity_dispensed');
        }

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
        try {
            $this->consultationWriter->update($consultation, $request->validated(), $request->user());
        } catch (InsufficientStockException $e) {
            throw $e->toValidationException('treatment.%d.quantity_dispensed');
        }

        $this->flashConsultation($consultation, 'updated');

        return redirect()->route('dashboard.index');
    }

    /**
     * Void the consultation: its dispensed stock returns to inventory and it
     * leaves every default list, but nothing is deleted and it can be
     * restored. A reason is required.
     */
    public function voidConsultation(VoidMedicalConsultationRequest $request, MedicalConsultation $consultation): RedirectResponse
    {
        $voided = $this->consultationVoiding->void($consultation, $request->user(), $request->validated('reason'));

        $this->flashConsultation($voided, 'voided');

        return redirect()->route('consultations.show', $voided);
    }

    /**
     * Restore a voided consultation, dispensing its treatment lines again.
     * A reason is required; insufficient stock rejects the restore.
     */
    public function restore(RestoreMedicalConsultationRequest $request, MedicalConsultation $consultation): RedirectResponse
    {
        $restored = $this->consultationVoiding->restore($consultation, $request->user(), $request->validated('reason'));

        $this->flashConsultation($restored, 'restored');

        return redirect()->route('consultations.show', $restored);
    }

    /**
     * Return one page of the consultation's change history, grouped by save.
     */
    public function history(Request $request, MedicalConsultation $consultation, ConsultationHistory $history): JsonResponse
    {
        $validated = $request->validate([
            'cursor' => ['nullable', 'integer', 'min:1'],
        ]);

        return response()->json($history->page($consultation, isset($validated['cursor']) ? (int) $validated['cursor'] : null));
    }

    /** @return array<string, mixed> */
    private function formOptions(): array
    {
        return [
            'medicalStateOptions' => array_column(MedicalState::cases(), 'value'),
            'medicalClassificationOptions' => array_column(MedicalClassification::cases(), 'value'),
            'transferTypeOptions' => array_column(TransferType::cases(), 'value'),
            'medicationOptions' => Medication::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
                ->map(fn (Medication $medication): array => $medication->only([
                    'uuid', 'name', 'presentation', 'concentration', 'dispensing_unit', 'current_stock',
                ]))
                ->all(),
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
            'voided_at' => $consultation->voided_at?->toIso8601String(),
            'voided_by' => $consultation->voidedBy ? ['name' => $consultation->voidedBy->name] : null,
            'void_reason' => $consultation->void_reason,
            'patient' => $this->mapPatient($consultation->patient),
            'physician' => ['name' => $consultation->physician->name],
            'consultation' => [
                'current_condition' => $consultation->current_condition,
                'diagnosis' => $consultation->diagnosis,
            ],
            'condition' => $consultation->condition->value,
            'prognosis' => $consultation->prognosis->value,
            'medical_classification' => $consultation->medical_classification->value,
            'treatment' => $consultation->treatments
                ->map(fn (MedicalConsultationTreatment $treatment): array => [
                    'medication' => [
                        'uuid' => $treatment->medication->uuid,
                        'name' => $treatment->medication->name,
                        'presentation' => $treatment->medication->presentation,
                        'concentration' => $treatment->medication->concentration,
                        'dispensing_unit' => $treatment->medication->dispensing_unit,
                        'is_active' => $treatment->medication->is_active,
                    ],
                    'quantity_dispensed' => $treatment->quantity_dispensed,
                    'dose' => $treatment->dose,
                    'frequency' => $treatment->frequency,
                    'duration' => $treatment->duration,
                ])
                ->all(),
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
            'can' => MedicalConsultationAbilities::for($request->user(), $consultation),
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
