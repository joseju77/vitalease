<?php

namespace App\Services\Inventory;

/**
 * Immutable result of a medication demand projection.
 *
 * `status` is `'ok'` when at least two complete months of consumption
 * history are available, otherwise `'insufficient_data'` and every derived
 * field (`fitted`, `projection`, `slope`, `intercept`, `rSquared`,
 * `nextMonthDemand`, `suggestedReorderQuantity`) is empty/null.
 */
final readonly class DemandProjectionResult
{
    /**
     * @param  'ok'|'insufficient_data'  $status
     * @param  list<array{month: string, consumed: int}>  $history
     * @param  list<array{month: string, value: float}>  $fitted
     * @param  list<array{month: string, value: float}>  $projection
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
    ) {}

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
        ];
    }
}
