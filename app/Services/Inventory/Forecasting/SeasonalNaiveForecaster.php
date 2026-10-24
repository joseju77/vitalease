<?php

namespace App\Services\Inventory\Forecasting;

/**
 * Forecasts the value observed one season earlier (lag 12 for monthly data;
 * evaluation-only baseline, never selectable in the app). It is unavailable,
 * not zero, with fewer than one full season of training data.
 */
final readonly class SeasonalNaiveForecaster implements Forecaster
{
    public const NAME = 'seasonal_naive_12';

    public function __construct(private int $period = 12) {}

    public function name(): string
    {
        return $this->period === 12 ? self::NAME : 'seasonal_naive_'.$this->period;
    }

    public function minimumTrainingLength(): int
    {
        return $this->period;
    }

    /**
     * @param  list<int|float>  $train
     * @return list<float>
     */
    public function forecast(array $train, int $horizon): array
    {
        $n = count($train);

        if ($n < $this->period) {
            throw new \InvalidArgumentException("Seasonal naive needs at least {$this->period} training months.");
        }

        $forecast = [];
        for ($i = 0; $i < $horizon; $i++) {
            $forecast[] = (float) $train[$n - $this->period + ($i % $this->period)];
        }

        return $forecast;
    }
}
