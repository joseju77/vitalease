<?php

use App\Enums\InventoryMovementType;
use App\Models\InventoryMovement;
use App\Models\MedicalConsultation;
use App\Models\Medication;
use App\Models\User;
use App\Services\Inventory\InsufficientStockException;
use App\Services\Inventory\TreatmentDispensation;
use Illuminate\Support\Facades\DB;

/**
 * @param  array<string, mixed>  $overrides
 * @return array{medication_uuid: string, quantity_dispensed: int, dose: string, frequency: string, duration: string}
 */
function dispensationLine(Medication $medication, int $quantity, array $overrides = []): array
{
    return array_replace([
        'medication_uuid' => $medication->uuid,
        'quantity_dispensed' => $quantity,
        'dose' => '500 mg',
        'frequency' => 'Cada 8 horas',
        'duration' => '5 días',
    ], $overrides);
}

function sync(MedicalConsultation $consultation, array $lines, User $actor): void
{
    DB::transaction(fn () => app(TreatmentDispensation::class)->sync($consultation, $lines, $actor));
}

describe('sync', function () {
    it('does not record a movement when a line quantity is unchanged but updates its other fields', function () {
        $medication = Medication::factory()->create(['current_stock' => 10]);
        $actor = User::factory()->create();
        $consultation = MedicalConsultation::factory()->withoutRegulation()->create();
        sync($consultation, [dispensationLine($medication, 4)], $actor);
        expect($medication->fresh()->current_stock)->toBe(6);

        sync($consultation, [dispensationLine($medication, 4, ['dose' => '1000 mg'])], $actor);

        expect($medication->fresh()->current_stock)->toBe(6)
            ->and(InventoryMovement::query()->count())->toBe(1)
            ->and($consultation->treatments()->sole()->dose)->toBe('1000 mg');
    });

    it('collects every insufficient line with its total dispensable capacity and writes no movement', function () {
        $shortOnStock = Medication::factory()->create(['current_stock' => 3]);
        $newlyRequested = Medication::factory()->create(['current_stock' => 1]);
        $actor = User::factory()->create();
        $consultation = MedicalConsultation::factory()->withoutRegulation()->create();
        sync($consultation, [dispensationLine($shortOnStock, 2)], $actor);
        expect($shortOnStock->fresh()->current_stock)->toBe(1);

        try {
            sync($consultation, [
                dispensationLine($shortOnStock, 10),
                dispensationLine($newlyRequested, 5),
            ], $actor);
            $this->fail('Expected InsufficientStockException was not thrown.');
        } catch (InsufficientStockException $e) {
            // available = current_stock + previously dispensed quantity for that medication.
            expect($e->available())->toBe([0 => 1 + 2, 1 => 1 + 0]);
        }

        expect($shortOnStock->fresh()->current_stock)->toBe(1)
            ->and($newlyRequested->fresh()->current_stock)->toBe(1)
            ->and($consultation->treatments()->sole()->quantity_dispensed)->toBe(2)
            ->and(InventoryMovement::query()->where('medication_id', $newlyRequested->id)->count())->toBe(0);
    });

    it('rejects a second consultation dispensing more than the stock left after a first consultation', function () {
        $medication = Medication::factory()->create(['current_stock' => 10]);
        $actor = User::factory()->create();
        $first = MedicalConsultation::factory()->withoutRegulation()->create();
        $second = MedicalConsultation::factory()->withoutRegulation()->create();

        sync($first, [dispensationLine($medication, 6)], $actor);
        expect($medication->fresh()->current_stock)->toBe(4);

        try {
            sync($second, [dispensationLine($medication, 8)], $actor);
            $this->fail('Expected InsufficientStockException was not thrown.');
        } catch (InsufficientStockException $e) {
            expect($e->available())->toBe([0 => 4]);
        }

        expect($medication->fresh()->current_stock)->toBe(4)
            ->and($second->treatments()->count())->toBe(0);
    });
});

describe('reverseAll', function () {
    it('returns the dispensed stock of every line with reversal movements and keeps the lines', function () {
        $first = Medication::factory()->create(['current_stock' => 20]);
        $second = Medication::factory()->create(['current_stock' => 20]);
        $actor = User::factory()->create();
        $consultation = MedicalConsultation::factory()->withoutRegulation()->create();
        sync($consultation, [
            dispensationLine($first, 5),
            dispensationLine($second, 3),
        ], $actor);
        expect($first->fresh()->current_stock)->toBe(15)
            ->and($second->fresh()->current_stock)->toBe(17);

        DB::transaction(fn () => app(TreatmentDispensation::class)->reverseAll($consultation, $actor));

        expect($first->fresh()->current_stock)->toBe(20)
            ->and($second->fresh()->current_stock)->toBe(20)
            ->and($consultation->treatments()->count())->toBe(2);

        $reversals = InventoryMovement::query()->where('type', InventoryMovementType::DispensationReversal)->get();
        expect($reversals)->toHaveCount(2)
            ->and($reversals->pluck('quantity')->sort()->values()->all())->toBe([3, 5])
            ->and($reversals->pluck('medical_consultation_id')->unique()->all())->toBe([$consultation->id]);
    });
});

describe('redispenseAll', function () {
    it('dispenses every preserved line again, even for an inactive medication', function () {
        $active = Medication::factory()->create(['current_stock' => 20]);
        $inactive = Medication::factory()->create(['current_stock' => 20]);
        $actor = User::factory()->create();
        $consultation = MedicalConsultation::factory()->withoutRegulation()->create();
        sync($consultation, [dispensationLine($active, 5), dispensationLine($inactive, 3)], $actor);
        DB::transaction(fn () => app(TreatmentDispensation::class)->reverseAll($consultation, $actor));
        $inactive->forceFill(['is_active' => false])->save();

        DB::transaction(fn () => app(TreatmentDispensation::class)->redispenseAll($consultation, $actor));

        expect($active->fresh()->current_stock)->toBe(15)
            ->and($inactive->fresh()->current_stock)->toBe(17)
            ->and($consultation->treatments()->count())->toBe(2);
    });

    it('reports every line without enough stock by position and writes nothing', function () {
        $first = Medication::factory()->create(['current_stock' => 20]);
        $second = Medication::factory()->create(['current_stock' => 20]);
        $actor = User::factory()->create();
        $consultation = MedicalConsultation::factory()->withoutRegulation()->create();
        sync($consultation, [dispensationLine($first, 5), dispensationLine($second, 3)], $actor);
        DB::transaction(fn () => app(TreatmentDispensation::class)->reverseAll($consultation, $actor));
        $second->forceFill(['current_stock' => 1])->save();
        $movementCount = InventoryMovement::query()->count();

        try {
            DB::transaction(fn () => app(TreatmentDispensation::class)->redispenseAll($consultation, $actor));
            $this->fail('Expected InsufficientStockException was not thrown.');
        } catch (InsufficientStockException $e) {
            expect($e->available())->toBe([1 => 1]);
        }

        expect($first->fresh()->current_stock)->toBe(20)
            ->and($second->fresh()->current_stock)->toBe(1)
            ->and(InventoryMovement::query()->count())->toBe($movementCount);
    });
});
