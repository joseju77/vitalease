<?php

namespace App\Services\Inventory\Forecasting;

/**
 * Extrapolates the ordinary-least-squares trend line fitted on the training
 * series.
 */
final readonly class LinearTrendForecaster implements Forecaster
{
    public const NAME = 'linear_trend';

    public function name(): string
    {
        return self::NAME;
    }

    public function minimumTrainingLength(): int
    {
        return 2;
    }

    /**
     * @param  list<int|float>  $train
     * @return list<float>
     */
    public function forecast(array $train, int $horizon): array
    {
        $n = count($train);

        if ($n < $this->minimumTrainingLength()) {
            throw new \InvalidArgumentException('Linear trend needs at least 2 training months.');
        }

        $fit = LinearTrendFit::fromSeries($train);

        $forecast = [];
        for ($i = 0; $i < $horizon; $i++) {
            $forecast[] = $fit->valueAt($n + $i);
        }

        return $forecast;
    }
}
