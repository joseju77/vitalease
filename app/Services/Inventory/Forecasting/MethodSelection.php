<?php

namespace App\Services\Inventory\Forecasting;

/**
 * Outcome of the in-app method selection rule. `method` is null only when
 * the history is too short to project at all (`insufficient_data`).
 */
final readonly class MethodSelection
{
    public const REASON_INSUFFICIENT_DATA = 'insufficient_data';

    public const REASON_SHORT_HISTORY = 'short_history';

    public const REASON_LINEAR_BEATS_MEAN = 'linear_beats_mean';

    public const REASON_MEAN_BEATS_LINEAR = 'mean_beats_linear';

    /**
     * @param  LinearTrendForecaster::NAME|HistoricalMeanForecaster::NAME|null  $method
     * @param  self::REASON_*  $reason
     */
    public function __construct(
        public ?string $method,
        public string $reason,
        public int $evaluatedPoints,
        public ?float $linearMae,
        public ?float $meanMae,
    ) {}

    public function isLinear(): bool
    {
        return $this->method === LinearTrendForecaster::NAME;
    }
}
