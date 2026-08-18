<?php

namespace App\Http\Controllers;

use App\Enums\InventoryMovementType;
use App\Http\Requests\Inventory\RecordAdjustmentRequest;
use App\Http\Requests\Inventory\RecordEntryRequest;
use App\Models\Medication;
use App\Services\Inventory\InsufficientStockException;
use App\Services\Inventory\StockLedger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class InventoryMovementController extends Controller
{
    public function __construct(private readonly StockLedger $stockLedger) {}

    /**
     * Register an Entry movement, increasing the medication's stock.
     */
    public function storeEntry(RecordEntryRequest $request, Medication $medication): RedirectResponse
    {
        DB::transaction(function () use ($request, $medication): void {
            $locked = $this->stockLedger->lock([$medication->id])->firstOrFail();

            $this->stockLedger->record(
                $locked,
                InventoryMovementType::Entry,
                $request->validated('quantity'),
                $request->user(),
                notes: $request->validated('notes'),
            );
        });

        Inertia::flash('medication', [
            'uuid' => $medication->uuid,
            'name' => $medication->name,
            'action' => 'entry_recorded',
        ]);

        return back();
    }

    /**
     * Register a manual Adjustment movement, which may increase or decrease
     * stock and always requires a note explaining the reason.
     */
    public function storeAdjustment(RecordAdjustmentRequest $request, Medication $medication): RedirectResponse
    {
        try {
            DB::transaction(function () use ($request, $medication): void {
                $locked = $this->stockLedger->lock([$medication->id])->firstOrFail();

                $this->stockLedger->record(
                    $locked,
                    InventoryMovementType::Adjustment,
                    $request->validated('quantity'),
                    $request->user(),
                    notes: $request->validated('notes'),
                );
            });
        } catch (InsufficientStockException $e) {
            throw $e->toValidationException('quantity');
        }

        Inertia::flash('medication', [
            'uuid' => $medication->uuid,
            'name' => $medication->name,
            'action' => 'adjustment_recorded',
        ]);

        return back();
    }
}
