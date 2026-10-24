<?php

namespace App\Services\Inventory\Forecasting\Evaluation;

use App\Services\Inventory\Forecasting\BacktestResult;
use App\Services\Inventory\Forecasting\DemandPatternClassifier;
use App\Services\Inventory\Forecasting\ErrorMetrics;
use App\Services\Inventory\Forecasting\Forecaster;
use App\Services\Inventory\Forecasting\HistoricalMeanForecaster;
use App\Services\Inventory\Forecasting\LinearTrendForecaster;
use App\Services\Inventory\Forecasting\MethodSelector;
use App\Services\Inventory\Forecasting\MovingAverageForecaster;
use App\Services\Inventory\Forecasting\NaiveForecaster;
use App\Services\Inventory\Forecasting\RollingOriginBacktester;
use App\Services\Inventory\Forecasting\SeasonalNaiveForecaster;

/**
 * Backtests every series with the five evaluated methods.
 *
 * - Main table: linear, historical mean, naive and MA(3) on common origins
 *   starting at `minTrain`, for every horizon.
 * - Subset table: series with at least 24 months, all five methods (seasonal
 *   naive needs 12 months of training) on common origins starting at
 *   `max(minTrain, 12)`.
 * - Online selection (h=1): at each origin `t >= 9` the in-app rule is applied
 *   with only the errors whose target precedes `t`, and the chosen method's
 *   forecast at `t` is scored. No future information is used and the
 *   backtest is not recomputed.
 */
final class EvaluationRunner
{
    public const MARGINS = [0.0, 0.05, 0.10];

    public const SUBSET_MIN_MONTHS = 24;

    public const SEASONAL_MIN_TRAIN = 12;

    public function __construct(private RollingOriginBacktester $backtester = new RollingOriginBacktester) {}

    /**
     * @param  list<EvaluationSeries>  $series
     * @param  list<int>  $horizons
     * @param  string  $prefix  Prefix of the anonymous index for series without a generating pattern.
     * @return \Generator<int, SeriesEvaluation> Lazily evaluated so memory stays flat for thousands of series.
     */
    public function run(array $series, array $horizons, int $minTrain, string $prefix): \Generator
    {
        usort($series, static fn (EvaluationSeries $a, EvaluationSeries $b): int => strcmp($a->key, $b->key));

        $position = 0;

        foreach ($series as $item) {
            $position++;
            $label = $item->source === null ? sprintf('%s-%05d', $prefix, $position) : $item->key;

            yield $this->evaluate($label, $item->values, $horizons, $minTrain);
        }
    }

    /**
     * @param  list<int>  $values
     * @param  list<int>  $horizons
     */
    public function evaluate(string $label, array $values, array $horizons, int $minTrain): SeriesEvaluation
    {
        $months = count($values);
        $pattern = DemandPatternClassifier::classify($values);
        $nonZero = count(array_filter($values, static fn (int $value): bool => $value !== 0));

        $metrics = [];
        $origins = [];
        $linearErrors = null;
        $meanErrors = null;
        $mainStart = null;

        foreach ($horizons as $horizon) {
            $result = $this->backtester->backtest($values, $this->mainForecasters(), $horizon, $minTrain);

            $metrics[$horizon] = $this->summarise($result, $values);
            $origins[$horizon] = $result->evaluated();

            if ($horizon === 1) {
                $linearErrors = $result->absoluteErrors(LinearTrendForecaster::NAME);
                $meanErrors = $result->absoluteErrors(HistoricalMeanForecaster::NAME);
                $mainStart = $result->origins[0] ?? null;
            }
        }

        $subset = null;
        if ($months >= self::SUBSET_MIN_MONTHS) {
            $subset = [];
            foreach ($horizons as $horizon) {
                $subset[$horizon] = $this->summarise(
                    $this->backtester->backtest($values, $this->subsetForecasters(), $horizon, max($minTrain, self::SEASONAL_MIN_TRAIN)),
                    $values,
                );
            }
        }

        $selection = $linearErrors === null || $meanErrors === null
            ? null
            : $this->selection($months, $linearErrors, $meanErrors, $mainStart);

        return new SeriesEvaluation($label, $pattern, $months, $nonZero, $metrics, $origins, $subset, $selection);
    }

    /**
     * @return list<Forecaster>
     */
    private function mainForecasters(): array
    {
        return [
            new LinearTrendForecaster,
            new HistoricalMeanForecaster,
            new NaiveForecaster,
            new MovingAverageForecaster,
        ];
    }

    /**
     * @return list<Forecaster>
     */
    private function subsetForecasters(): array
    {
        return [...$this->mainForecasters(), new SeasonalNaiveForecaster];
    }

    /**
     * @param  list<int>  $values
     * @return array<string, array{mae: ?float, rmse: ?float, mase: ?float}>
     */
    private function summarise(BacktestResult $result, array $values): array
    {
        $summary = [];

        foreach (array_keys($result->methods) as $method) {
            $errors = $result->absoluteErrors($method);
            $mae = $errors === [] ? null : (float) (array_sum($errors) / count($errors));

            $summary[$method] = [
                'mae' => $mae,
                'rmse' => $result->rmse($method),
                'mase' => $mae === null ? null : ErrorMetrics::mase($mae, $values),
            ];
        }

        return $summary;
    }

    /**
     * @param  list<float>  $linearErrors  h=1 absolute errors, one per origin.
     * @param  list<float>  $meanErrors
     * @return array{selects_linear: array<string, bool>, online: array{origins: int, mae: array<string, ?float>}|null}
     */
    private function selection(int $months, array $linearErrors, array $meanErrors, ?int $start): array
    {
        $selectsLinear = [];
        foreach (self::MARGINS as $margin) {
            $selectsLinear[self::marginKey($margin)] = MethodSelector::choose($months, $linearErrors, $meanErrors, $margin)->isLinear();
        }

        $online = null;

        if ($start !== null) {
            $first = max($start, MethodSelector::MIN_HISTORY_FOR_SELECTION);
            $count = $months - $first;

            if ($count > 0) {
                $sums = ['linear_trend' => 0.0, 'historical_mean' => 0.0];
                foreach (self::MARGINS as $margin) {
                    $sums[self::marginKey($margin)] = 0.0;
                }

                for ($origin = $first; $origin < $months; $origin++) {
                    $slot = $origin - $start;

                    $sums['linear_trend'] += $linearErrors[$slot];
                    $sums['historical_mean'] += $meanErrors[$slot];

                    $priorLinear = array_slice($linearErrors, 0, $slot);
                    $priorMean = array_slice($meanErrors, 0, $slot);

                    foreach (self::MARGINS as $margin) {
                        $chosen = MethodSelector::choose($origin, $priorLinear, $priorMean, $margin);
                        $sums[self::marginKey($margin)] += $chosen->isLinear() ? $linearErrors[$slot] : $meanErrors[$slot];
                    }
                }

                $online = [
                    'origins' => $count,
                    'mae' => array_map(static fn (float $sum): float => $sum / $count, $sums),
                ];
            }
        }

        return ['selects_linear' => $selectsLinear, 'online' => $online];
    }

    public static function marginKey(float $margin): string
    {
        return 'selected_margin_'.(int) round($margin * 100);
    }
}
