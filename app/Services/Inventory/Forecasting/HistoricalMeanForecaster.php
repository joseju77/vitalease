<?php

namespace App\Services\Inventory\Forecasting;

/**
 * Forecasts the arithmetic mean of the whole training series.
 */
final readonly class HistoricalMeanForecaster implements Forecaster
{
    public const NAME = 'historical_mean';

    public function name(): string
    {
        return self::NAME;
    }

    public function minimumTrainingLength(): int
    {
        return 1;
    }

    /**
     * @param  list<int|float>  $train
     * @return list<float>
     */
    public function forecast(array $train, int $horizon): array
    {
        if ($train === []) {
            throw new \InvalidArgumentException('Historical mean needs at least 1 training month.');
        }

        return array_fill(0, $horizon, array_sum($train) / count($train));
    }
}
