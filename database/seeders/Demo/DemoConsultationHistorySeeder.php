<?php

namespace Database\Seeders\Demo;

use App\Enums\InventoryMovementType;
use App\Models\InventoryMovement;
use App\Models\MedicalConsultation;
use App\Models\MedicalConsultationTreatment;
use App\Models\Medication;
use App\Models\User;
use App\Services\Inventory\StockLedger;
use App\Services\MedicalConsultations\ConsultationVoiding;
use App\Services\MedicalConsultations\ConsultationWriter;
use Closure;
use Database\Seeders\Concerns\RunsWithAuditing;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @phpstan-import-type ConsultationData from ConsultationWriter
 */
class DemoConsultationHistorySeeder extends Seeder
{
    use RunsWithAuditing;

    /**
     * Minutes between two consecutive curated operations.
     */
    private const int STEP_MINUTES = 20;

    /**
     * Units dispensed when a treatment line is added to a consultation.
     */
    private const int ADDED_LINE_QUANTITY = 2;

    private ?Carbon $lastSlot = null;

    public function __construct(
        private readonly ConsultationWriter $writer,
        private readonly ConsultationVoiding $voiding,
        private readonly StockLedger $stockLedger,
    ) {}

    /**
     * Give the curated demo consultations (the audited ones created by
     * {@see DemoConsultationSeeder}, oldest first) a realistic history
     * through the real application services:
     *
     * 1. vital signs and diagnosis edited;
     * 2. a treatment line added;
     * 3. a treatment line removed (after adding one when it had a single line);
     * 4. voided by the demo physician (the owner) with a reason;
     * 5. edited, then voided by medical coordination with a reason;
     * 6. voided and then restored by medical coordination.
     *
     * Fewer curated consultations simply skip the later steps. Every
     * operation is pinned in time after the last baseline event, so the
     * audit trail and the inventory ledger stay chronologically coherent,
     * and runs in its own audit batch with the acting user set.
     *
     * Requires `DemoConsultationSeeder` and `DemoUserSeeder` to have run.
     */
    public function run(): void
    {
        /** @var Collection<int, MedicalConsultation> $curated */
        $curated = MedicalConsultation::query()
            ->whereHas('audits')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->values();

        if ($curated->isEmpty()) {
            return;
        }

        $physician = User::query()->where('email', DemoUserSeeder::DEMO_PHYSICIAN_EMAIL)->firstOrFail();
        $coordinator = User::query()->where('email', DemoUserSeeder::DEMO_COORDINATOR_EMAIL)->firstOrFail();

        $this->lastSlot = $this->latestBaselineEvent();

        if ($first = $curated->get(0)) {
            $this->editVitalSignsAndDiagnosis($first, $physician);
        }

        if ($second = $curated->get(1)) {
            $this->addTreatmentLine($second, $physician);
        }

        if ($third = $curated->get(2)) {
            $this->removeTreatmentLine($third, $physician);
        }

        if ($fourth = $curated->get(3)) {
            $this->voidAs($fourth, $physician, 'Registro duplicado: el paciente ya contaba con una consulta equivalente el mismo día.');
        }

        if ($fifth = $curated->get(4)) {
            $this->updateAs($fifth, $physician, fn (array $data): array => [
                ...$data,
                'consultation' => [
                    ...$data['consultation'],
                    'current_condition' => $data['consultation']['current_condition'].' Se aclara en nota posterior que el motivo de consulta fue registrado en el expediente equivocado.',
                ],
            ]);
            $this->voidAs($fifth, $coordinator, 'Paciente registrado por error; la consulta corresponde a otro expediente.');
        }

        if ($sixth = $curated->get(5)) {
            $this->voidAs($sixth, $coordinator, 'Captura realizada con datos de otro paciente, pendiente de verificar.');
            $this->restoreAs($sixth, $coordinator, 'Se verificó el expediente: la consulta sí corresponde al paciente.');
        }
    }

    private function editVitalSignsAndDiagnosis(MedicalConsultation $consultation, User $actor): void
    {
        $this->updateAs($consultation, $actor, function (array $data): array {
            $vitalSigns = $data['vital_signs'];

            return [
                ...$data,
                'consultation' => [
                    ...$data['consultation'],
                    'diagnosis' => $data['consultation']['diagnosis'].' (diagnóstico ajustado tras revaloración)',
                ],
                'vital_signs' => [
                    ...$vitalSigns,
                    'heart_rate' => (int) $vitalSigns['heart_rate'] + 6,
                    'temperature' => round((float) $vitalSigns['temperature'] + 0.4, 1),
                    'oxygen_saturation' => max(80, (int) $vitalSigns['oxygen_saturation'] - 2),
                ],
            ];
        });
    }

    private function addTreatmentLine(MedicalConsultation $consultation, User $actor): void
    {
        $presentIds = $consultation->treatments()->pluck('medication_id');

        $medication = Medication::query()
            ->where('is_active', true)
            ->where('current_stock', '>=', self::ADDED_LINE_QUANTITY)
            ->whereNotIn('id', $presentIds)
            ->orderBy('id')
            ->first();

        if ($medication === null) {
            return;
        }

        $this->updateAs($consultation, $actor, function (array $data) use ($medication): array {
            $data['treatment'][] = [
                'medication_uuid' => $medication->uuid,
                'quantity_dispensed' => self::ADDED_LINE_QUANTITY,
                'dose' => '1 unidad',
                'frequency' => 'Cada 12 horas',
                'duration' => '3 días',
            ];

            return $data;
        });
    }

    private function removeTreatmentLine(MedicalConsultation $consultation, User $actor): void
    {
        // A single-line treatment first gets an extra line, so a removal is
        // always part of the curated history.
        if ($consultation->treatments()->count() < 2) {
            $this->addTreatmentLine($consultation, $actor);
        }

        if ($consultation->treatments()->count() < 2) {
            return;
        }

        $this->updateAs($consultation, $actor, function (array $data): array {
            array_pop($data['treatment']);

            return $data;
        });
    }

    /**
     * @param  Closure(ConsultationData): ConsultationData  $change
     */
    private function updateAs(MedicalConsultation $consultation, User $actor, Closure $change): void
    {
        $this->atNextSlot($actor, function () use ($consultation, $actor, $change): void {
            $this->writer->update($consultation, $change($this->currentData($consultation)), $actor);
        });
    }

    private function voidAs(MedicalConsultation $consultation, User $actor, string $reason): void
    {
        $this->atNextSlot($actor, fn () => $this->voiding->void($consultation, $actor, $reason));
    }

    private function restoreAs(MedicalConsultation $consultation, User $actor, string $reason): void
    {
        $this->atNextSlot($actor, function () use ($consultation, $actor, $reason): void {
            $this->ensureStockToRestore($consultation, $actor);

            $this->voiding->restore($consultation, $actor, $reason);
        });
    }

    /**
     * Top up, through the ledger, any medication the restore would
     * otherwise overdraw, so the demo never ends with negative stock.
     */
    private function ensureStockToRestore(MedicalConsultation $consultation, User $actor): void
    {
        $lines = MedicalConsultationTreatment::query()
            ->where('medical_consultation_id', $consultation->id)
            ->orderBy('medication_id')
            ->get();

        DB::transaction(function () use ($lines, $actor): void {
            $locked = $this->stockLedger->lock($lines->pluck('medication_id')->all());

            foreach ($lines as $line) {
                $medication = $locked->get($line->medication_id);
                $missing = $line->quantity_dispensed - $medication->current_stock;

                if ($missing > 0) {
                    $this->stockLedger->record(
                        $medication,
                        InventoryMovementType::Entry,
                        $missing,
                        $actor,
                        notes: 'Reabastecimiento previo a la restauración de una consulta.',
                    );
                }
            }
        });
    }

    /**
     * Rebuild the writer payload from the consultation's persisted state.
     *
     * @return ConsultationData
     */
    private function currentData(MedicalConsultation $consultation): array
    {
        $consultation = MedicalConsultation::query()
            ->with(['vitalSigns', 'physicalExamination', 'regulation', 'treatments.medication'])
            ->findOrFail($consultation->id);

        $regulation = $consultation->regulation?->only([
            'transfer_type', 'ambulance_registration', 'regulation_number', 'clinic_id', 'regulated_at', 'receiver_physician',
        ]);

        return [
            'consultation' => [
                'current_condition' => $consultation->current_condition,
                'diagnosis' => $consultation->diagnosis,
            ],
            'condition' => $consultation->condition,
            'prognosis' => $consultation->prognosis,
            'medical_classification' => $consultation->medical_classification,
            'vital_signs' => $consultation->vitalSigns->only([
                'weight', 'height', 'blood_pressure_systolic', 'blood_pressure_diastolic', 'heart_rate',
                'respiratory_rate', 'temperature', 'oxygen_saturation', 'glasgow', 'glucose',
            ]),
            'physical_examination' => $consultation->physicalExamination->only([
                'neurological', 'head_neck', 'thorax_cardiopulmonary', 'abdomen', 'extremities', 'cabinet_laboratory',
            ]),
            'regulation' => $regulation,
            'treatment' => $consultation->treatments->sortBy('id')->map(fn (MedicalConsultationTreatment $line): array => [
                'medication_uuid' => $line->medication->uuid,
                'quantity_dispensed' => $line->quantity_dispensed,
                'dose' => $line->dose,
                'frequency' => $line->frequency,
                'duration' => $line->duration,
            ])->values()->all(),
        ];
    }

    /**
     * Run one operation pinned to the next free time slot, with auditing
     * on and the acting user set.
     *
     * @param  Closure(): mixed  $operation
     */
    private function atNextSlot(User $actor, Closure $operation): void
    {
        $slot = $this->lastSlot->copy()->addMinutes(self::STEP_MINUTES);
        $now = Carbon::now();

        $this->lastSlot = $slot->greaterThan($now) ? $now->copy() : $slot;

        Carbon::withTestNow($this->lastSlot, fn () => $this->withAuditing($actor, $operation));
    }

    /**
     * The newest consultation or ledger timestamp, so curated operations
     * always land after everything the baseline wrote.
     */
    private function latestBaselineEvent(): Carbon
    {
        $latest = Carbon::parse(MedicalConsultation::withTrashed()->max('created_at'));
        $lastMovement = InventoryMovement::query()->max('created_at');

        if ($lastMovement !== null && Carbon::parse($lastMovement)->greaterThan($latest)) {
            return Carbon::parse($lastMovement);
        }

        return $latest;
    }
}
