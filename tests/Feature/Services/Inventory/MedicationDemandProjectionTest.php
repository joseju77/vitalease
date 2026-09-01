<?php

use App\Enums\InventoryMovementType;
use App\Models\MedicalConsultation;
use App\Models\Medication;
use App\Models\User;
use App\Services\Inventory\MedicationDemandProjection;
use App\Services\Inventory\StockLedger;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Record a real ledger movement at an exact UTC instant, by freezing time to
 * that instant before creating the consultation the movement is attributed
 * to (Dispensation/DispensationReversal are timestamped from the
 * consultation's creation date).
 */
function recordAt(Carbon $occurredAtUtc, Medication $medication, InventoryMovementType $type, int $quantity, User $actor): void
{
    Carbon::setTestNow($occurredAtUtc);
    $consultation = MedicalConsultation::factory()->withoutRegulation()->create();

    DB::transaction(fn () => app(StockLedger::class)->record(
        app(StockLedger::class)->lock([$medication->id])->get($medication->id),
        $type,
        $quantity,
        $actor,
        $consultation,
    ));
}

describe('forMedication', function () {
    it('buckets by America/Mexico_City calendar month, zero-fills gaps, nets reversals, and excludes the incomplete current month', function () {
        $medication = Medication::factory()->create(['current_stock' => 100]);
        $actor = User::factory()->create();

        // 2026-03-01 03:00 UTC is 2026-02-28 21:00 in America/Mexico_City — still February.
        recordAt(Carbon::create(2026, 3, 1, 3, 0, 0, 'UTC'), $medication, InventoryMovementType::Dispensation, -5, $actor);

        // No movement at all in March (America/Mexico_City) — the month must zero-fill.

        // April, comfortably inside the same calendar day in both UTC and America/Mexico_City.
        recordAt(Carbon::create(2026, 4, 10, 18, 0, 0, 'UTC'), $medication, InventoryMovementType::Dispensation, -3, $actor);
        recordAt(Carbon::create(2026, 4, 15, 18, 0, 0, 'UTC'), $medication, InventoryMovementType::DispensationReversal, 1, $actor);

        // Early May: excluded from the series because it is the current,
        // incomplete month relative to `asOf` below.
        recordAt(Carbon::create(2026, 5, 2, 18, 0, 0, 'UTC'), $medication, InventoryMovementType::Dispensation, -2, $actor);

        Carbon::setTestNow();

        $result = app(MedicationDemandProjection::class)->forMedication(
            $medication,
            horizon: 1,
            asOf: CarbonImmutable::create(2026, 5, 3, 10, 0, 0, 'America/Mexico_City'),
        );

        expect($result->history)->toBe([
            ['month' => '2026-02', 'consumed' => 5],
            ['month' => '2026-03', 'consumed' => 0],
            ['month' => '2026-04', 'consumed' => 2],
        ])
            ->and($result->status)->toBe('ok');
    });

    it('returns insufficient_data when no consumption movement exists yet', function () {
        $medication = Medication::factory()->create(['current_stock' => 10]);

        $result = app(MedicationDemandProjection::class)->forMedication($medication, asOf: CarbonImmutable::create(2026, 1, 1));

        expect($result->status)->toBe('insufficient_data')
            ->and($result->history)->toBe([])
            ->and($result->currentStock)->toBe(10);
    });
});
