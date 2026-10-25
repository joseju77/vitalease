<?php

namespace App\Services\Inventory\Forecasting\Evaluation;

/**
 * One monthly demand series prepared for evaluation. The key is a hidden,
 * sortable identifier (it may embed a practice code for NHS data) and must
 * never be written to committed output; reports use anonymous indices.
 */
final readonly class EvaluationSeries
{
    /**
     * @param  list<int>  $values  Monthly demand, oldest first.
     * @param  string|null  $source  Generating pattern for synthetic series.
     */
    public function __construct(
        public string $key,
        public array $values,
        public ?string $source = null,
    ) {}
}
