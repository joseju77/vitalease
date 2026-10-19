<?php

namespace App\Services\Inventory;

use App\Enums\InventoryMovementType;
use App\Models\MedicalConsultation;
use App\Models\MedicalConsultationTreatment;
use App\Models\Medication;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Owns a consultation's treatment lines and keeps them in lockstep with the
 * inventory ledger: every call to `sync()` reconciles the submitted lines
 * against the consultation's existing lines, writes exactly one
 * compensating `Dispensation`/`DispensationReversal` movement per affected
 * medication through `StockLedger`, and then persists the resulting line
 * rows — all inside the caller's existing database transaction.
 */
final class TreatmentDispensation
{
    public function __construct(private readonly StockLedger $stockLedger) {}

    /**
     * Reconcile a consultation's treatment lines with the given submitted
     * lines, dispensing or restoring stock for the net per-medication
     * quantity change and persisting the resulting line rows.
     *
     * Must run inside an open database transaction.
     *
     * @param  list<array{medication_uuid: string, quantity_dispensed: int, dose: string, frequency: string, duration: string}>  $lines
     *
     * @throws LogicException When called outside a database transaction.
     * @throws InsufficientStockException When any line would dispense more than its medication's current stock.
     */
    public function sync(MedicalConsultation $consultation, array $lines, User $actor): void
    {
        $this->assertInTransaction(__FUNCTION__);

        $uuidToId = Medication::query()
            ->whereIn('uuid', array_column($lines, 'medication_uuid'))
            ->pluck('id', 'uuid');

        /** @var Collection<int, MedicalConsultationTreatment> $old */
        $old = $consultation->treatments()->get()->keyBy('medication_id');

        /** @var array<int, array{index: int, quantity_dispensed: int, dose: string, frequency: string, duration: string}> $new */
        $new = [];
        foreach ($lines as $index => $line) {
            $medicationId = $uuidToId[$line['medication_uuid']];

            $new[$medicationId] = [
                'index' => $index,
                'quantity_dispensed' => $line['quantity_dispensed'],
                'dose' => $line['dose'],
                'frequency' => $line['frequency'],
                'duration' => $line['duration'],
            ];
        }

        $medicationIds = array_unique([...$old->keys()->all(), ...array_keys($new)]);
        sort($medicationIds);

        $locked = $this->stockLedger->lock($medicationIds);

        // Per-medication delta between the submitted and the existing
        // quantity. Positive means more stock must be dispensed; negative
        // means previously dispensed stock must be restored.
        $deltas = [];
        $insufficient = [];

        foreach ($medicationIds as $medicationId) {
            $oldQuantity = $old->get($medicationId)?->quantity_dispensed ?? 0;
            $newQuantity = $new[$medicationId]['quantity_dispensed'] ?? 0;
            $delta = $newQuantity - $oldQuantity;
            $deltas[$medicationId] = $delta;

            if ($delta > 0 && $delta > $locked->get($medicationId)->current_stock) {
                $insufficient[$new[$medicationId]['index']] = $locked->get($medicationId)->current_stock + $oldQuantity;
            }
        }

        if ($insufficient !== []) {
            throw new InsufficientStockException($insufficient);
        }

        foreach ($medicationIds as $medicationId) {
            $delta = $deltas[$medicationId];

            if ($delta === 0) {
                continue;
            }

            $type = $delta > 0 ? InventoryMovementType::Dispensation : InventoryMovementType::DispensationReversal;

            $this->stockLedger->record($locked->get($medicationId), $type, -$delta, $actor, $consultation);
        }

        $removedIds = $old->keys()->diff(array_keys($new));

        if ($removedIds->isNotEmpty()) {
            // Model-by-model deletes so each removed line writes its audit.
            $old->each(function (MedicalConsultationTreatment $line) use ($removedIds): void {
                if ($removedIds->contains($line->medication_id)) {
                    $line->delete();
                }
            });
        }

        foreach ($new as $medicationId => $line) {
            $consultation->treatments()->updateOrCreate(
                ['medication_id' => $medicationId],
                [
                    'quantity_dispensed' => $line['quantity_dispensed'],
                    'dose' => $line['dose'],
                    'frequency' => $line['frequency'],
                    'duration' => $line['duration'],
                ],
            );
        }
    }

    /**
     * Return to stock everything the consultation's treatment lines
     * dispensed, writing one `DispensationReversal` movement per line. The
     * lines themselves are preserved so the consultation can be restored.
     *
     * Must run inside an open database transaction.
     *
     * @throws LogicException When called outside a database transaction.
     */
    public function reverseAll(MedicalConsultation $consultation, User $actor): void
    {
        $this->assertInTransaction(__FUNCTION__);

        $lines = $consultation->treatments()->orderBy('id')->get();
        $locked = $this->stockLedger->lock($lines->pluck('medication_id')->sort()->values()->all());

        foreach ($lines as $line) {
            $this->stockLedger->record(
                $locked->get($line->medication_id),
                InventoryMovementType::DispensationReversal,
                $line->quantity_dispensed,
                $actor,
                $consultation,
            );
        }
    }

    /**
     * Dispense again everything the consultation's preserved treatment
     * lines call for, writing one `Dispensation` movement per line. Inactive
     * medications do not block this, but stock does: when any line lacks
     * stock nothing is written and every offending line is reported.
     *
     * Must run inside an open database transaction.
     *
     * @throws LogicException When called outside a database transaction.
     * @throws InsufficientStockException When any line exceeds its medication's current stock, keyed by line position (ordered by line id).
     */
    public function redispenseAll(MedicalConsultation $consultation, User $actor): void
    {
        $this->assertInTransaction(__FUNCTION__);

        $lines = $consultation->treatments()->orderBy('id')->get();
        $locked = $this->stockLedger->lock($lines->pluck('medication_id')->sort()->values()->all());

        $insufficient = [];

        foreach ($lines->values() as $position => $line) {
            $available = $locked->get($line->medication_id)->current_stock;

            if ($line->quantity_dispensed > $available) {
                $insufficient[$position] = $available;
            }
        }

        if ($insufficient !== []) {
            throw new InsufficientStockException($insufficient);
        }

        foreach ($lines as $line) {
            $this->stockLedger->record(
                $locked->get($line->medication_id),
                InventoryMovementType::Dispensation,
                -$line->quantity_dispensed,
                $actor,
                $consultation,
            );
        }
    }

    /**
     * @throws LogicException When called outside a database transaction.
     */
    private function assertInTransaction(string $method): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException("TreatmentDispensation::{$method}() must be called inside a database transaction.");
        }
    }
}
