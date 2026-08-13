<?php

namespace App\Services\Inventory;

use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Thrown when a `StockLedger` or `TreatmentDispensation` operation would
 * reduce one or more medications' stock below zero.
 */
class InsufficientStockException extends RuntimeException
{
    /**
     * @param  array<int, int>  $available  Available stock per offending line, keyed by line index.
     */
    public function __construct(private readonly array $available)
    {
        parent::__construct('Insufficient stock for one or more inventory movements.');
    }

    /**
     * @return array<int, int>
     */
    public function available(): array
    {
        return $this->available;
    }

    /**
     * Build a `ValidationException` from the per-line availability map.
     *
     * When `$keyPattern` contains a `%d` placeholder, each offending line
     * index is interpolated into it (e.g. `treatment.%d.quantity_dispensed`
     * for a multi-line dispensation). Otherwise the same key is reused for
     * every entry (e.g. `quantity`), which is correct for single-line
     * Entry/Adjustment movements that always report line index 0.
     */
    public function toValidationException(string $keyPattern): ValidationException
    {
        $messages = [];

        foreach ($this->available as $lineIndex => $available) {
            $key = str_contains($keyPattern, '%d') ? sprintf($keyPattern, $lineIndex) : $keyPattern;

            $messages[$key] = [trans('validation.custom.insufficient_stock', ['available' => $available])];
        }

        return ValidationException::withMessages($messages);
    }
}
