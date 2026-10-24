<?php

namespace App\Services\Inventory;

use App\Enums\InventoryMovementType;
use App\Models\Medication;
use App\Services\Inventory\Forecasting\DemandPatternClassifier;
use App\Services\Inventory\Forecasting\HistoricalMeanForecaster;
use App\Services\Inventory\Forecasting\LinearTrendFit;
use App\Services\Inventory\Forecasting\LinearTrendForecaster;
use App\Services\Inventory\Forecasting\MethodSelector;
use App\Services\Inventory\Forecasting\RollingOriginBacktester;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Projects a medication's future monthly demand from its historical
 * consumption. `forMedication()` backtests a linear trend (ordinary least
 * squares) against the historical mean on the medication's own series and
 * projects with the one that errs less (see `MethodSelector`);
 * `fromSeries()` is the plain linear fit. Read-only: it never writes to the
 * ledger.
 */
final class MedicationDemandProjection
{
    private const TIMEZONE = 'America/Mexico_City';

    /**
     * Aggregate the medication's Dispensation/DispensationReversal
     * movements into calendar months (America/Mexico_City), zero-fill the
     * range between the first consumption month and the last complete month
     * before `$asOf`, and project it with the method chosen by backtest.
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

        return $this->projectWithSelection($series, $firstMonth, $medication->current_stock, $horizon);
    }

    /**
     * Backtest linear trend vs historical mean on the series (one-step,
     * expanding window, identical origins), choose with `MethodSelector` and
     * project with the chosen method. With the historical mean the
     * fitted line and projection are flat at the mean, the next-month demand
     * is its ceiling and slope, intercept and R² are null.
     *
     * @param  list<int>  $series  Monthly consumption, one value per month starting at `$firstMonth`.
     */
    public function projectWithSelection(array $series, CarbonImmutable $firstMonth, int $currentStock, int $horizon = 3): DemandProjectionResult
    {
        $n = count($series);

        if ($n < MethodSelector::MIN_SERIES_LENGTH) {
            return $this->fromSeries($series, $firstMonth, $currentStock, $horizon);
        }

        $backtest = (new RollingOriginBacktester)->backtest(
            $series,
            [new LinearTrendForecaster, new HistoricalMeanForecaster],
            1,
        );

        $selection = MethodSelector::choose(
            $n,
            $backtest->absoluteErrors(LinearTrendForecaster::NAME),
            $backtest->absoluteErrors(HistoricalMeanForecaster::NAME),
        );

        $summary = [
            'horizon' => 1,
            'margin' => MethodSelector::DEFAULT_MARGIN,
            'evaluated_points' => $backtest->evaluated(),
            LinearTrendForecaster::NAME => [
                'mae' => $backtest->mae(LinearTrendForecaster::NAME),
                'rmse' => $backtest->rmse(LinearTrendForecaster::NAME),
            ],
            HistoricalMeanForecaster::NAME => [
                'mae' => $backtest->mae(HistoricalMeanForecaster::NAME),
                'rmse' => $backtest->rmse(HistoricalMeanForecaster::NAME),
            ],
        ];

        $pattern = DemandPatternClassifier::classify($series)->toArray();

        $projected = $selection->isLinear()
            ? $this->fromSeries($series, $firstMonth, $currentStock, $horizon)
            : $this->historicalMeanProjection($series, $firstMonth, $currentStock, $horizon);

        return $projected->withSelection((string) $selection->method, $selection->reason, $summary, $pattern);
    }

    /**
     * Flat projection at the historical mean of the whole series.
     *
     * @param  list<int>  $series
     */
    private function historicalMeanProjection(array $series, CarbonImmutable $firstMonth, int $currentStock, int $horizon): DemandProjectionResult
    {
        $n = count($series);
        $mean = array_sum($series) / $n;
        $flat = round(max(0.0, $mean), 2);

        $history = [];
        $fitted = [];
        $cursor = $firstMonth;
        foreach ($series as $consumed) {
            $month = $cursor->format('Y-m');
            $history[] = ['month' => $month, 'consumed' => $consumed];
            $fitted[] = ['month' => $month, 'value' => $flat];
            $cursor = $cursor->addMonthNoOverflow();
        }

        $projection = [];
        for ($i = 0; $i < $horizon; $i++) {
            $projection[] = ['month' => $cursor->format('Y-m'), 'value' => $flat];
            $cursor = $cursor->addMonthNoOverflow();
        }

        $nextMonthDemand = $projection === [] ? null : (int) ceil(round($mean, 9));
        $suggestedReorderQuantity = $nextMonthDemand === null ? null : max(0, $nextMonthDemand - $currentStock);

        return new DemandProjectionResult(
            status: 'ok',
            history: $history,
            fitted: $fitted,
            projection: $projection,
            slope: null,
            intercept: null,
            rSquared: null,
            nextMonthDemand: $nextMonthDemand,
            currentStock: $currentStock,
            suggestedReorderQuantity: $suggestedReorderQuantity,
        );
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

        $fit = LinearTrendFit::fromSeries($series);
        $slope = $fit->slope;
        $intercept = $fit->intercept;
        $rSquared = $fit->rSquared;

        $fitted = [];
        $cursor = $firstMonth;
        foreach (array_keys($series) as $x) {
            $fitted[] = ['month' => $cursor->format('Y-m'), 'value' => round($fit->valueAt($x), 2)];
            $cursor = $cursor->addMonthNoOverflow();
        }

        $projection = [];
        $cursor = $firstMonth->addMonths($n);
        for ($i = 0; $i < $horizon; $i++) {
            $value = max(0.0, $fit->valueAt($n + $i));
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
            method: LinearTrendForecaster::NAME,
        );
    }
}
