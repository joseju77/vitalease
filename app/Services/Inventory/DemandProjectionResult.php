<?php

namespace App\Services\Inventory;

/**
 * Immutable result of a medication demand projection.
 *
 * `status` is `'ok'` when at least two complete months of consumption
 * history are available, otherwise `'insufficient_data'` and every derived
 * field (`fitted`, `projection`, `slope`, `intercept`, `rSquared`,
 * `nextMonthDemand`, `suggestedReorderQuantity`) is empty/null.
 *
 * `method` is the projection method that produced the numbers
 * (`linear_trend` or `historical_mean`), `methodReason` why it was chosen,
 * `backtest` the one-step rolling-origin error of both candidates and
 * `demandPattern` the Syntetos-Boylan class of the series. They are null
 * when not computed (`insufficient_data`).
 */
final readonly class DemandProjectionResult
{
    /**
     * @param  'ok'|'insufficient_data'  $status
     * @param  list<array{month: string, consumed: int}>  $history
     * @param  list<array{month: string, value: float}>  $fitted
     * @param  list<array{month: string, value: float}>  $projection
     * @param  'linear_trend'|'historical_mean'|null  $method
     * @param  'insufficient_data'|'short_history'|'linear_beats_mean'|'mean_beats_linear'|null  $methodReason
     * @param  array{horizon: int, margin: float, evaluated_points: int, linear_trend: array{mae: ?float, rmse: ?float}, historical_mean: array{mae: ?float, rmse: ?float}}|null  $backtest
     * @param  array{class: string, adi: ?float, cv2: ?float, seasonality: string}|null  $demandPattern
     */
    public function __construct(
        public string $status,
        public array $history,
        public array $fitted,
        public array $projection,
        public ?float $slope,
        public ?float $intercept,
        public ?float $rSquared,
        public ?int $nextMonthDemand,
        public int $currentStock,
        public ?int $suggestedReorderQuantity,
        public ?string $method = null,
        public ?string $methodReason = null,
        public ?array $backtest = null,
        public ?array $demandPattern = null,
    ) {}

    /**
     * Copy of this result annotated with the selection metadata.
     *
     * @param  array<string, mixed>|null  $backtest
     * @param  array<string, mixed>|null  $demandPattern
     */
    public function withSelection(string $method, string $methodReason, ?array $backtest, ?array $demandPattern): self
    {
        return new self(
            status: $this->status,
            history: $this->history,
            fitted: $this->fitted,
            projection: $this->projection,
            slope: $this->slope,
            intercept: $this->intercept,
            rSquared: $this->rSquared,
            nextMonthDemand: $this->nextMonthDemand,
            currentStock: $this->currentStock,
            suggestedReorderQuantity: $this->suggestedReorderQuantity,
            method: $method,
            methodReason: $methodReason,
            backtest: $backtest,
            demandPattern: $demandPattern,
        );
    }

    /**
     * Build a result for a series with fewer than two complete months of
     * history, where an OLS fit is not meaningful.
     *
     * @param  list<array{month: string, consumed: int}>  $history
     */
    public static function insufficientData(array $history, int $currentStock): self
    {
        return new self(
            status: 'insufficient_data',
            history: $history,
            fitted: [],
            projection: [],
            slope: null,
            intercept: null,
            rSquared: null,
            nextMonthDemand: null,
            currentStock: $currentStock,
            suggestedReorderQuantity: null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'history' => $this->history,
            'fitted' => $this->fitted,
            'projection' => $this->projection,
            'slope' => $this->slope,
            'intercept' => $this->intercept,
            'r_squared' => $this->rSquared,
            'next_month_demand' => $this->nextMonthDemand,
            'current_stock' => $this->currentStock,
            'suggested_reorder_quantity' => $this->suggestedReorderQuantity,
            'method' => $this->method,
            'method_reason' => $this->methodReason,
            'backtest' => $this->backtest,
            'demand_pattern' => $this->demandPattern,
        ];
    }
}
