<?php

namespace App\Services\Inventory\Forecasting;

/**
 * Expanding-window rolling-origin evaluation. For every origin `t` from the
 * common starting point up to `n - horizon`, each forecaster is fitted on
 * `series[0..t-1]` only and scored against `series[t + horizon - 1]` (a direct
 * forecast from the same origin). Forecasts are clamped at zero before
 * scoring, matching the in-app projection, and all forecasters share
 * identical origins.
 */
final class RollingOriginBacktester
{
    public const DEFAULT_MIN_TRAIN = 6;

    /**
     * @param  list<int|float>  $series  Monthly demand, oldest first.
     * @param  list<Forecaster>  $forecasters
     */
    public function backtest(array $series, array $forecasters, int $horizon, int $minTrain = self::DEFAULT_MIN_TRAIN): BacktestResult
    {
        if ($horizon < 1) {
            throw new \InvalidArgumentException('Horizon must be at least 1.');
        }

        $series = array_values($series);
        $n = count($series);

        $start = $minTrain;
        foreach ($forecasters as $forecaster) {
            $start = max($start, $forecaster->minimumTrainingLength());
        }

        $origins = [];
        $actuals = [];
        $forecastsByMethod = [];
        $errorsByMethod = [];

        foreach ($forecasters as $forecaster) {
            $forecastsByMethod[$forecaster->name()] = [];
            $errorsByMethod[$forecaster->name()] = [];
        }

        for ($origin = $start; $origin <= $n - $horizon; $origin++) {
            $train = array_slice($series, 0, $origin);
            $actual = $series[$origin + $horizon - 1];

            $origins[] = $origin;
            $actuals[] = $actual;

            foreach ($forecasters as $forecaster) {
                $predicted = max(0.0, $forecaster->forecast($train, $horizon)[$horizon - 1]);

                $forecastsByMethod[$forecaster->name()][] = $predicted;
                $errorsByMethod[$forecaster->name()][] = abs($actual - $predicted);
            }
        }

        $methods = [];
        foreach ($forecasters as $forecaster) {
            $name = $forecaster->name();
            $mae = ErrorMetrics::mae($actuals, $forecastsByMethod[$name]);
            $rmse = ErrorMetrics::rmse($actuals, $forecastsByMethod[$name]);

            $methods[$name] = [
                'forecasts' => array_map(static fn (float $value): float => round($value, 4), $forecastsByMethod[$name]),
                'absolute_errors' => $errorsByMethod[$name],
                'mae' => $mae === null ? null : round($mae, 4),
                'rmse' => $rmse === null ? null : round($rmse, 4),
            ];
        }

        return new BacktestResult($horizon, $origins, $actuals, $methods);
    }
}
