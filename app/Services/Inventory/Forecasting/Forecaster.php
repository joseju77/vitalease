<?php

namespace App\Services\Inventory\Forecasting;

/**
 * A stateless point forecaster over a monthly demand series.
 *
 * Implementations receive only the training series, so a forecast can never
 * depend on observations after the forecast origin. Forecasts are returned
 * unclamped; callers clamp at zero where demand cannot be negative.
 */
interface Forecaster
{
    /**
     * Stable identifier used as the method key in results and reports.
     */
    public function name(): string;

    /**
     * Smallest training length for which this forecaster is defined.
     */
    public function minimumTrainingLength(): int;

    /**
     * Forecast the next `$horizon` months after the end of `$train`.
     *
     * @param  list<int|float>  $train  Monthly demand, oldest first.
     * @return list<float> One value per step ahead, `$horizon` entries.
     */
    public function forecast(array $train, int $horizon): array;
}
