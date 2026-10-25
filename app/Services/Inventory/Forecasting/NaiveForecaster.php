<?php

namespace App\Services\Inventory\Forecasting;

/**
 * Forecasts the last observed value.
 */
final readonly class NaiveForecaster implements Forecaster
{
    public const NAME = 'naive';

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
            throw new \InvalidArgumentException('Naive needs at least 1 training month.');
        }

        return array_fill(0, $horizon, (float) $train[array_key_last($train)]);
    }
}
