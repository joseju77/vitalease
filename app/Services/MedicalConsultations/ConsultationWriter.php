<?php

namespace App\Services\MedicalConsultations;

use App\Models\MedicalConsultation;
use App\Models\Patient;
use App\Models\User;
use App\Services\Inventory\InsufficientStockException;
use App\Services\Inventory\TreatmentDispensation;
use App\Support\Auditing\AuditBatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Writes a consultation aggregate (consultation, vital signs, physical
 * examination, optional regulation and treatment lines) atomically. Every
 * audit row produced by one call shares a single audit batch, so one save
 * reads as one change in the history.
 *
 * `$data` is the validated request payload.
 *
 * @phpstan-type ConsultationData array{
 *     consultation: array{current_condition: string, diagnosis: string},
 *     condition: mixed,
 *     prognosis: mixed,
 *     medical_classification: mixed,
 *     vital_signs: array<string, mixed>,
 *     physical_examination: array<string, mixed>,
 *     regulation: array<string, mixed>|null,
 *     treatment: list<array{medication_uuid: string, quantity_dispensed: int, dose: string, frequency: string, duration: string}>
 * }
 */
final readonly class ConsultationWriter
{
    public function __construct(
        private TreatmentDispensation $treatmentDispensation,
        private AuditBatch $auditBatch,
    ) {}

    /**
     * Create the consultation aggregate for the given patient, authored by
     * the actor.
     *
     * @param  ConsultationData  $data
     *
     * The patient is re-read under a shared lock, serialising with a
     * concurrent disable (which takes `FOR UPDATE`): a patient disabled
     * meanwhile is rejected, for every actor.
     *
     * @throws InsufficientStockException When a treatment line exceeds the available stock.
     * @throws ValidationException When the patient is disabled (error on `patient_uuid`).
     */
    public function create(Patient $patient, array $data, User $actor): MedicalConsultation
    {
        return $this->auditBatch->within(fn (): MedicalConsultation => DB::transaction(function () use ($patient, $data, $actor): MedicalConsultation {
            $patient = Patient::query()->whereKey($patient->getKey())->sharedLock()->firstOrFail();

            if ($patient->disabled_at !== null) {
                throw ValidationException::withMessages([
                    'patient_uuid' => [__('modules/patients/management.errors.consultation_patient_disabled')],
                ]);
            }

            $consultation = MedicalConsultation::query()->create([
                'current_condition' => $data['consultation']['current_condition'],
                'diagnosis' => $data['consultation']['diagnosis'],
                'condition' => $data['condition'],
                'prognosis' => $data['prognosis'],
                'medical_classification' => $data['medical_classification'],
                'physician_id' => $actor->id,
                'patient_id' => $patient->id,
            ]);

            $consultation->vitalSigns()->create($data['vital_signs']);
            $consultation->physicalExamination()->create($data['physical_examination']);

            if ($data['regulation'] !== null) {
                $consultation->regulation()->create($data['regulation']);
            }

            $this->treatmentDispensation->sync($consultation, $data['treatment'], $actor);

            return $consultation;
        }));
    }

    /**
     * Update the consultation aggregate under a row lock. The patient, code
     * and physician never change on update.
     *
     * @param  ConsultationData  $data
     *
     * @throws InsufficientStockException When a treatment line exceeds the available stock.
     */
    public function update(MedicalConsultation $consultation, array $data, User $actor): void
    {
        $this->auditBatch->within(fn () => DB::transaction(function () use ($consultation, $data, $actor): void {
            $locked = MedicalConsultation::query()
                ->whereKey($consultation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $locked->update([
                'current_condition' => $data['consultation']['current_condition'],
                'diagnosis' => $data['consultation']['diagnosis'],
                'condition' => $data['condition'],
                'prognosis' => $data['prognosis'],
                'medical_classification' => $data['medical_classification'],
            ]);

            $locked->vitalSigns->update($data['vital_signs']);
            $locked->physicalExamination->update($data['physical_examination']);

            if ($data['regulation'] === null) {
                // Model delete (not a query-builder delete) so the removal is audited.
                $locked->regulation?->delete();
            } else {
                $locked->regulation()->updateOrCreate([], $data['regulation']);
            }

            $this->treatmentDispensation->sync($locked, $data['treatment'], $actor);
        }));
    }
}
