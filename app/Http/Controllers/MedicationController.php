<?php

namespace App\Http\Controllers;

use App\Http\Requests\Inventory\StoreMedicationRequest;
use App\Http\Requests\Inventory\UpdateMedicationRequest;
use App\Models\InventoryMovement;
use App\Models\Medication;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Scout\Builder as ScoutBuilder;

class MedicationController extends Controller
{
    /**
     * Display a paginated, searchable medication catalog.
     *
     * Free-text search runs through Meilisearch (`Medication::search()`);
     * without a query the catalog is read directly from Postgres. The
     * low-stock filter applies on both paths: a `whereColumn` on the plain
     * query, and a `where('is_low_stock', ...)` Scout filter backed by the
     * precomputed `is_low_stock` filterable attribute, since Meilisearch
     * cannot compare two attributes of the same document to each other.
     */
    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'low_stock' => ['nullable', 'boolean'],
        ]);

        $query = trim((string) ($validated['q'] ?? ''));
        $lowStock = $request->boolean('low_stock');

        if ($query !== '') {
            $medications = Medication::search($query)
                ->when($lowStock, fn (ScoutBuilder $builder) => $builder->where('is_low_stock', true))
                ->orderBy('name')
                ->paginate(15)
                ->withQueryString();
        } else {
            $medications = Medication::query()
                ->when($lowStock, fn (Builder $builder) => $builder->whereColumn('current_stock', '<=', 'minimum_stock'))
                ->orderBy('name')
                ->orderBy('id')
                ->paginate(15)
                ->withQueryString();
        }

        return Inertia::render('inventory/Index', [
            'medications' => $medications->through(fn (Medication $medication): array => $this->mapMedication($medication)),
            'filters' => [
                'q' => $query !== '' ? $query : null,
                'low_stock' => $lowStock,
            ],
        ]);
    }

    /**
     * Store a newly created medication.
     *
     * `current_stock` is never an accepted key: a medication is always
     * created with zero stock, and any initial quantity must be registered
     * afterward as an Entry movement through
     * `InventoryMovementController::storeEntry()`.
     */
    public function store(StoreMedicationRequest $request): RedirectResponse
    {
        $medication = Medication::query()->create($request->validated());

        $this->flashMedication($medication, 'created');

        return redirect()->route('inventory.index');
    }

    /**
     * Display one medication's detail alongside its paginated movement
     * history, most recent first.
     */
    public function show(Medication $medication): Response
    {
        $movements = $medication->inventoryMovements()
            ->with('user:id,name')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('inventory/Show', [
            'medication' => $this->mapMedication($medication),
            'movements' => $movements->through(fn (InventoryMovement $movement): array => $this->mapMovement($movement)),
        ]);
    }

    /**
     * Update the target medication's catalog data.
     *
     * `current_stock` is never an accepted key: only `StockLedger` may
     * change it.
     */
    public function update(UpdateMedicationRequest $request, Medication $medication): RedirectResponse
    {
        $medication->update($request->validated());

        $this->flashMedication($medication, 'updated');

        return back();
    }

    /**
     * Flag the medication as active again, without touching its stock or
     * movement history.
     */
    public function activate(Medication $medication): RedirectResponse
    {
        $medication->update(['is_active' => true]);

        $this->flashMedication($medication, 'activated');

        return back();
    }

    /**
     * Flag the medication as inactive, without touching its stock or
     * movement history.
     */
    public function deactivate(Medication $medication): RedirectResponse
    {
        $medication->update(['is_active' => false]);

        $this->flashMedication($medication, 'deactivated');

        return back();
    }

    /**
     * Hard-delete a medication.
     *
     * Rejected with a 422 validation error once the medication has any
     * movement or treatment line, so its history is never lost. The
     * `restrictOnDelete()` foreign keys on both tables back this up at the
     * database level.
     */
    public function destroy(Medication $medication): RedirectResponse
    {
        if ($medication->inventoryMovements()->exists() || $medication->treatments()->exists()) {
            throw ValidationException::withMessages([
                'medication' => [__('modules/inventory/management.custom.has_history')],
            ]);
        }

        $medication->delete();

        $this->flashMedication($medication, 'deleted');

        return redirect()->route('inventory.index');
    }

    /** @return array<string, mixed> */
    private function mapMedication(Medication $medication): array
    {
        return [
            'uuid' => $medication->uuid,
            'name' => $medication->name,
            'presentation' => $medication->presentation,
            'concentration' => $medication->concentration,
            'dispensing_unit' => $medication->dispensing_unit,
            'current_stock' => $medication->current_stock,
            'minimum_stock' => $medication->minimum_stock,
            'is_active' => $medication->is_active,
            'is_low_stock' => $medication->isLowStock(),
        ];
    }

    /** @return array<string, mixed> */
    private function mapMovement(InventoryMovement $movement): array
    {
        return [
            'id' => $movement->id,
            'type' => $movement->type->value,
            'quantity' => $movement->quantity,
            'stock_after' => $movement->stock_after,
            'medical_consultation_code' => $movement->medical_consultation_code,
            'user' => $movement->user?->name,
            'notes' => $movement->notes,
            'occurred_at' => $movement->occurred_at->toIso8601String(),
        ];
    }

    private function flashMedication(Medication $medication, string $action): void
    {
        Inertia::flash('medication', [
            'uuid' => $medication->uuid,
            'name' => $medication->name,
            'action' => $action,
        ]);
    }
}
