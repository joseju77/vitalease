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

describe('restoreAll', function () {
    it('restores stock for every dispensed medication and removes every treatment line', function () {
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

        DB::transaction(fn () => app(TreatmentDispensation::class)->restoreAll($consultation, $actor));

        expect($first->fresh()->current_stock)->toBe(20)
            ->and($second->fresh()->current_stock)->toBe(20)
            ->and($consultation->treatments()->count())->toBe(0);

        $reversals = InventoryMovement::query()->where('type', InventoryMovementType::DispensationReversal)->get();
        expect($reversals)->toHaveCount(2)
            ->and($reversals->pluck('quantity')->sort()->values()->all())->toBe([3, 5]);
    });
});
