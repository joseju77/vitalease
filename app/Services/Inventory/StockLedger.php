<?php

namespace App\Services\Inventory;

use App\Enums\InventoryMovementType;
use App\Models\InventoryMovement;
use App\Models\MedicalConsultation;
use App\Models\Medication;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The single code path allowed to change `medications.current_stock`. Every
 * call to `record()` writes exactly one `InventoryMovement` row in the same
 * database transaction as the stock change, keeping the ledger append-only
 * and the running balance always consistent with its movement history.
 */
final class StockLedger
{
    /**
     * Lock the given medications for update, ordered by id so concurrent
     * callers touching overlapping medication sets always acquire their
     * locks in the same order and cannot deadlock each other.
     *
     * @param  array<int, int>  $medicationIds
     * @return Collection<int, Medication> Keyed by medication id.
     */
    public function lock(array $medicationIds): Collection
    {
        return Medication::query()
            ->whereIn('id', $medicationIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    /**
     * Apply a signed stock movement to an already-locked medication and
     * record it in the ledger.
     *
     * Must run inside an open database transaction, on a `Medication`
     * instance previously returned by `lock()`. `$consultation` is required
     * for `Dispensation`/`DispensationReversal` movements, whose
     * `occurred_at` is attributed to the consultation's creation date so
     * consumption stays tied to the clinical event rather than the moment
     * the ledger entry is written; `Entry`/`Adjustment` movements are
     * timestamped with the current time instead.
     *
     * @throws LogicException When called outside a database transaction.
     * @throws InsufficientStockException When the resulting stock would be negative.
     */
    public function record(
        Medication $locked,
        InventoryMovementType $type,
        int $quantity,
        User $actor,
        ?MedicalConsultation $consultation = null,
        ?string $notes = null,
    ): InventoryMovement {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('StockLedger::record() must be called inside a database transaction.');
        }

        $stockAfter = $locked->current_stock + $quantity;

        if ($stockAfter < 0) {
            throw new InsufficientStockException([0 => $locked->current_stock]);
        }

        $isConsultationMovement = in_array(
            $type,
            [InventoryMovementType::Dispensation, InventoryMovementType::DispensationReversal],
            true,
        );

        $movement = new InventoryMovement([
            'medication_id' => $locked->id,
            'type' => $type,
            'quantity' => $quantity,
            'stock_after' => $stockAfter,
            'medical_consultation_id' => $isConsultationMovement ? $consultation?->id : null,
            'medical_consultation_code' => $isConsultationMovement ? $consultation?->code : null,
            'user_id' => $actor->id,
            'notes' => $notes,
            'occurred_at' => $isConsultationMovement ? $consultation?->created_at : now(),
        ]);
        $movement->save();

        $locked->current_stock = $stockAfter;
        $locked->save();

        return $movement;
    }
}
