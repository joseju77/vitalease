<?php

namespace App\Services\Inventory\Forecasting\Evaluation;

use App\Services\Inventory\Forecasting\DemandPattern;

/**
 * Evaluation outcome of one series. `label` is the anonymous index written to
 * reports; the hidden series key never reaches this object.
 *
 * `metrics` and `subset` map horizon => method => MAE (full precision), RMSE
 * and MASE. `subset` is only filled for series long enough for the common
 * origin set that includes the seasonal naive method. `selection` holds the
 * h=1 selection results per margin (key: margin as a string).
 */
final readonly class SeriesEvaluation
{
    /**
     * @param  array<int, array<string, array{mae: ?float, rmse: ?float, mase: ?float}>>  $metrics
     * @param  array<int, array<string, array{mae: ?float, rmse: ?float, mase: ?float}>>|null  $subset
     * @param  array{
     *     selects_linear: array<string, bool|null>,
     *     online: array{origins: int, mae: array<string, ?float>}|null
     * }|null  $selection
     * @param  array<int, int>  $origins  Evaluated origins of the main backtest per horizon.
     */
    public function __construct(
        public string $label,
        public DemandPattern $pattern,
        public int $months,
        public int $nonZeroMonths,
        public array $metrics,
        public array $origins,
        public ?array $subset,
        public ?array $selection,
    ) {}
}
