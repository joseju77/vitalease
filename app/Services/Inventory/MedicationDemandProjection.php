<?php

namespace App\Services\Inventory;

use App\Enums\InventoryMovementType;
use App\Models\Medication;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Projects a medication's future monthly demand from its historical
 * consumption, using a plain ordinary-least-squares fit over the observed
 * calendar months. Read-only: it never writes to the ledger.
 */
final class MedicationDemandProjection
{
    private const TIMEZONE = 'America/Mexico_City';

    /**
     * Aggregate the medication's Dispensation/DispensationReversal
     * movements into calendar months (America/Mexico_City), zero-fill the
     * range between the first consumption month and the last complete month
     * before `$asOf`, and fit an OLS projection over it.
     */
    public function forMedication(Medication $medication, int $horizon = 3, ?CarbonImmutable $asOf = null): DemandProjectionResult
    {
        $asOf ??= CarbonImmutable::now(self::TIMEZONE);

        $rows = DB::select(
            <<<'SQL'
                select date_trunc('month', occurred_at AT TIME ZONE 'America/Mexico_City') as month,
                       -sum(quantity) as consumed
                from inventory_movements
                where medication_id = ?
                  and type in (?, ?)
                group by 1
                order by 1
                SQL,
            [
                $medication->id,
                InventoryMovementType::Dispensation->value,
                InventoryMovementType::DispensationReversal->value,
            ],
        );

        if ($rows === []) {
            return DemandProjectionResult::insufficientData([], $medication->current_stock);
        }

        /** @var array<string, int> $consumedByMonth */
        $consumedByMonth = [];
        foreach ($rows as $row) {
            $consumedByMonth[substr((string) $row->month, 0, 7)] = (int) $row->consumed;
        }

        $firstMonth = CarbonImmutable::parse((string) $rows[0]->month)->startOfMonth();
        $lastCompleteMonth = $asOf->startOfMonth()->subMonthNoOverflow();

        $series = [];
        $cursor = $firstMonth;
        while ($cursor->lessThanOrEqualTo($lastCompleteMonth)) {
            $series[] = $consumedByMonth[$cursor->format('Y-m')] ?? 0;
            $cursor = $cursor->addMonthNoOverflow();
        }

        return $this->fromSeries($series, $firstMonth, $medication->current_stock, $horizon);
    }

    /**
     * Pure OLS fit over an already zero-filled monthly consumption series.
     * Public so it can be exercised directly with hand-computed fixtures.
     *
     * Fits `y = a + b*x` with `x = 0..n-1` (one point per calendar month
     * starting at `$firstMonth`): `b = Sxy/Sxx`, `a = ȳ - b*x̄`,
     * `R² = 1 - SSres/SStot`, with `R² = 1.0` when `SStot = 0` (a constant
     * series is a perfect fit) and `b = 0` when `Sxx = 0` (fewer than two
     * distinct x values). Projected values are clamped at 0; the fitted
     * line over historical months is not.
     *
     * @param  list<int>  $series  Monthly consumption, one value per month starting at `$firstMonth`.
     */
    public function fromSeries(array $series, CarbonImmutable $firstMonth, int $currentStock, int $horizon = 3): DemandProjectionResult
    {
        $n = count($series);

        $history = [];
        $cursor = $firstMonth;
        foreach ($series as $consumed) {
            $history[] = ['month' => $cursor->format('Y-m'), 'consumed' => $consumed];
            $cursor = $cursor->addMonthNoOverflow();
        }

        if ($n < 2) {
            return DemandProjectionResult::insufficientData($history, $currentStock);
        }

        $xMean = ($n - 1) / 2;
        $yMean = array_sum($series) / $n;

        $sxy = 0.0;
        $sxx = 0.0;
        $syy = 0.0;

        foreach ($series as $x => $y) {
            $dx = $x - $xMean;
            $dy = $y - $yMean;
            $sxy += $dx * $dy;
            $sxx += $dx * $dx;
            $syy += $dy * $dy;
        }

        $slope = $sxx > 0.0 ? $sxy / $sxx : 0.0;
        $intercept = $yMean - $slope * $xMean;

        $ssRes = 0.0;
        foreach ($series as $x => $y) {
            $ssRes += ($y - ($intercept + $slope * $x)) ** 2;
        }

        $rSquared = $syy > 0.0 ? 1.0 - ($ssRes / $syy) : 1.0;

        $fitted = [];
        $cursor = $firstMonth;
        foreach (array_keys($series) as $x) {
            $fitted[] = ['month' => $cursor->format('Y-m'), 'value' => round($intercept + $slope * $x, 2)];
            $cursor = $cursor->addMonthNoOverflow();
        }

        $projection = [];
        $cursor = $firstMonth->addMonths($n);
        for ($i = 0; $i < $horizon; $i++) {
            $value = max(0.0, $intercept + $slope * ($n + $i));
            $projection[] = ['month' => $cursor->format('Y-m'), 'value' => round($value, 2)];
            $cursor = $cursor->addMonthNoOverflow();
        }

        $nextMonthDemand = $projection === [] ? null : (int) ceil($projection[0]['value']);
        $suggestedReorderQuantity = $nextMonthDemand === null ? null : max(0, $nextMonthDemand - $currentStock);

        return new DemandProjectionResult(
            status: 'ok',
            history: $history,
            fitted: $fitted,
            projection: $projection,
            slope: round($slope, 4),
            intercept: round($intercept, 4),
            rSquared: round($rSquared, 4),
            nextMonthDemand: $nextMonthDemand,
            currentStock: $currentStock,
            suggestedReorderQuantity: $suggestedReorderQuantity,
        );
    }
}
