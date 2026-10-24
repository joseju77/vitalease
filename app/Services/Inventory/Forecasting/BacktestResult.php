<?php

namespace App\Services\Inventory\Forecasting;

/**
 * Outcome of a rolling-origin backtest for one horizon.
 *
 * `origins` holds the training size of each origin (so the forecast target of
 * origin `t` is the observation at index `t + horizon - 1`). Forecasts and
 * summary errors are rounded to 4 decimals; `absolute_errors` keep full
 * precision so selection rules never depend on display rounding.
 */
final readonly class BacktestResult
{
    /**
     * @param  list<int>  $origins
     * @param  list<int|float>  $actuals
     * @param  array<string, array{forecasts: list<float>, absolute_errors: list<float>, mae: ?float, rmse: ?float}>  $methods
     */
    public function __construct(
        public int $horizon,
        public array $origins,
        public array $actuals,
        public array $methods,
    ) {}

    public function evaluated(): int
    {
        return count($this->origins);
    }

    public function mae(string $method): ?float
    {
        return $this->methods[$method]['mae'] ?? null;
    }

    public function rmse(string $method): ?float
    {
        return $this->methods[$method]['rmse'] ?? null;
    }

    /**
     * @return list<float>
     */
    public function forecasts(string $method): array
    {
        return $this->methods[$method]['forecasts'] ?? [];
    }

    /**
     * @return list<float>
     */
    public function absoluteErrors(string $method): array
    {
        return $this->methods[$method]['absolute_errors'] ?? [];
    }
}
