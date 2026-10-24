<?php

namespace App\Services\Inventory\Forecasting;

/**
 * Forecasts the mean of the last `$window` observations (evaluation-only
 * baseline, never selectable in the app).
 */
final readonly class MovingAverageForecaster implements Forecaster
{
    public const NAME = 'moving_average_3';

    public function __construct(private int $window = 3) {}

    public function name(): string
    {
        return $this->window === 3 ? self::NAME : 'moving_average_'.$this->window;
    }

    public function minimumTrainingLength(): int
    {
        return $this->window;
    }

    /**
     * @param  list<int|float>  $train
     * @return list<float>
     */
    public function forecast(array $train, int $horizon): array
    {
        if (count($train) < $this->window) {
            throw new \InvalidArgumentException("Moving average needs at least {$this->window} training months.");
        }

        $recent = array_slice($train, -$this->window);

        return array_fill(0, $horizon, array_sum($recent) / $this->window);
    }
}
