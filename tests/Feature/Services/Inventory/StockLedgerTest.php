<?php

use App\Enums\InventoryMovementType;
use App\Models\InventoryMovement;
use App\Models\MedicalConsultation;
use App\Models\Medication;
use App\Models\User;
use App\Services\Inventory\InsufficientStockException;
use App\Services\Inventory\StockLedger;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Lock and record in one step, matching the calling convention every real
 * caller (`InventoryMovementController`, `TreatmentDispensation`) uses.
 */
function recordMovement(
    Medication $medication,
    InventoryMovementType $type,
    int $quantity,
    User $actor,
    ?MedicalConsultation $consultation = null,
    ?string $notes = null,
): InventoryMovement {
    return DB::transaction(fn () => app(StockLedger::class)->record(
        app(StockLedger::class)->lock([$medication->id])->get($medication->id),
        $type,
        $quantity,
        $actor,
        $consultation,
        $notes,
    ));
}

describe('lock ordering', function () {
    it('locks and returns medications keyed by id in ascending order regardless of input order', function () {
        $ids = Medication::factory()->count(3)->create()->pluck('id')->sort()->values()->all();
        $shuffled = $ids;
        shuffle($shuffled);

        $locked = app(StockLedger::class)->lock($shuffled);

        expect($locked->keys()->all())->toBe($ids);
    });
});

describe('recording a movement', function () {
    it('keeps stock_after consistent across a chain of movements on the same medication', function () {
        $medication = Medication::factory()->create(['current_stock' => 10]);
        $actor = User::factory()->create();

        $entry = recordMovement($medication, InventoryMovementType::Entry, 5, $actor);
        $decrease = recordMovement($medication, InventoryMovementType::Adjustment, -3, $actor, notes: 'shrinkage');
        $increase = recordMovement($medication, InventoryMovementType::Adjustment, 2, $actor, notes: 'recount');

        expect($entry->stock_after)->toBe(15)
            ->and($decrease->stock_after)->toBe(12)
            ->and($increase->stock_after)->toBe(14)
            ->and($medication->fresh()->current_stock)->toBe(14);
    });

    it('throws InsufficientStockException and leaves stock unchanged when a movement would go negative', function () {
        $medication = Medication::factory()->create(['current_stock' => 5]);
        $actor = User::factory()->create();

        expect(fn () => recordMovement($medication, InventoryMovementType::Adjustment, -10, $actor, notes: 'too much'))
            ->toThrow(InsufficientStockException::class);

        expect($medication->fresh()->current_stock)->toBe(5)
            ->and(InventoryMovement::query()->count())->toBe(0);
    });

    it('reports the available stock on InsufficientStockException', function () {
        $medication = Medication::factory()->create(['current_stock' => 5]);
        $actor = User::factory()->create();

        try {
            recordMovement($medication, InventoryMovementType::Adjustment, -10, $actor, notes: 'too much');
        } catch (InsufficientStockException $e) {
            expect($e->available())->toBe([0 => 5]);

            return;
        }

        $this->fail('Expected InsufficientStockException was not thrown.');
    });

    it('snapshots the consultation id and code on a Dispensation movement', function () {
        $consultation = MedicalConsultation::factory()->withoutRegulation()->create();
        $medication = Medication::factory()->create(['current_stock' => 10]);
        $actor = User::factory()->create();

        $movement = recordMovement($medication, InventoryMovementType::Dispensation, -2, $actor, $consultation);

        expect($movement->medical_consultation_id)->toBe($consultation->id)
            ->and($movement->medical_consultation_code)->toBe($consultation->code);
    });

    it('snapshots the consultation id and code on a DispensationReversal movement', function () {
        $consultation = MedicalConsultation::factory()->withoutRegulation()->create();
        $medication = Medication::factory()->create(['current_stock' => 10]);
        $actor = User::factory()->create();

        $movement = recordMovement($medication, InventoryMovementType::DispensationReversal, 2, $actor, $consultation);

        expect($movement->medical_consultation_id)->toBe($consultation->id)
            ->and($movement->medical_consultation_code)->toBe($consultation->code);
    });

    it('never links a consultation to an Entry or Adjustment movement even when one is passed', function () {
        $consultation = MedicalConsultation::factory()->withoutRegulation()->create();
        $medication = Medication::factory()->create(['current_stock' => 10]);
        $actor = User::factory()->create();

        $movement = recordMovement($medication, InventoryMovementType::Entry, 5, $actor, $consultation);

        expect($movement->medical_consultation_id)->toBeNull()
            ->and($movement->medical_consultation_code)->toBeNull();
    });

    it('attributes occurred_at to the consultation creation date for Dispensation and DispensationReversal', function () {
        $this->travelTo(Carbon::create(2026, 3, 1, 8, 0, 0));
        $consultation = MedicalConsultation::factory()->withoutRegulation()->create();
        $consultationCreatedAt = $consultation->created_at;

        $this->travelTo(Carbon::create(2026, 3, 5, 12, 0, 0));
        $medication = Medication::factory()->create(['current_stock' => 10]);
        $actor = User::factory()->create();

        $movement = recordMovement($medication, InventoryMovementType::Dispensation, -2, $actor, $consultation);

        $this->travelBack();

        expect($movement->occurred_at->equalTo($consultationCreatedAt))->toBeTrue();
    });

    it('attributes occurred_at to the current time for Entry and Adjustment movements', function () {
        $this->travelTo(Carbon::create(2026, 3, 5, 12, 0, 0));
        $medication = Medication::factory()->create(['current_stock' => 10]);
        $actor = User::factory()->create();

        $movement = recordMovement($medication, InventoryMovementType::Entry, 5, $actor);

        $this->travelBack();

        expect($movement->occurred_at->equalTo(Carbon::create(2026, 3, 5, 12, 0, 0)))->toBeTrue();
    });
});
