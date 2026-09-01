<?php

use App\Services\Inventory\DemandProjectionResult;
use App\Services\Inventory\MedicationDemandProjection;
use Carbon\CarbonImmutable;

/**
 * @param  list<int>  $series
 */
function projectFrom(array $series, int $currentStock, int $horizon = 3): DemandProjectionResult
{
    return (new MedicationDemandProjection)->fromSeries($series, CarbonImmutable::create(2026, 1, 1), $currentStock, $horizon);
}

describe('fromSeries', function () {
    it('fits a perfectly linear series with the expected slope, intercept, and R-squared', function () {
        $result = projectFrom([10, 12, 14, 16], currentStock: 0, horizon: 1);

        expect($result->status)->toBe('ok')
            ->and($result->slope)->toBe(2.0)
            ->and($result->intercept)->toBe(10.0)
            ->and($result->rSquared)->toBe(1.0);
    });

    it('fits a noisy series with hand-computed slope, intercept, and R-squared to 4 decimals', function () {
        // series = [1, 2, 4, 3, 5]; xMean = 2, yMean = 3; Sxy = 9, Sxx = 10, Syy = 10
        // slope = 0.9, intercept = 1.2, SSres = 1.9, R² = 1 - 1.9/10 = 0.81
        $result = projectFrom([1, 2, 4, 3, 5], currentStock: 2, horizon: 1);

        expect($result->slope)->toBe(0.9)
            ->and($result->intercept)->toBe(1.2)
            ->and($result->rSquared)->toBe(0.81)
            ->and($result->nextMonthDemand)->toBe(6)
            ->and($result->suggestedReorderQuantity)->toBe(4);
    });

    it('returns insufficient_data with no coefficients for a single month of history', function () {
        $result = projectFrom([7], currentStock: 3);

        expect($result->status)->toBe('insufficient_data')
            ->and($result->slope)->toBeNull()
            ->and($result->intercept)->toBeNull()
            ->and($result->rSquared)->toBeNull()
            ->and($result->fitted)->toBe([])
            ->and($result->projection)->toBe([])
            ->and($result->nextMonthDemand)->toBeNull()
            ->and($result->suggestedReorderQuantity)->toBeNull()
            ->and($result->currentStock)->toBe(3)
            ->and($result->history)->toBe([['month' => '2026-01', 'consumed' => 7]]);
    });

    it('fits a constant series with a zero slope and a perfect R-squared', function () {
        $result = projectFrom([5, 5, 5, 5], currentStock: 5, horizon: 1);

        expect($result->slope)->toBe(0.0)
            ->and($result->intercept)->toBe(5.0)
            ->and($result->rSquared)->toBe(1.0)
            ->and($result->projection[0]['value'])->toBe(5.0);
    });

    it('clamps a negative-trend projection at zero instead of a negative value', function () {
        $result = projectFrom([10, 5, 0], currentStock: 4, horizon: 3);

        expect($result->slope)->toBe(-5.0)
            ->and($result->fitted)->toBe([
                ['month' => '2026-01', 'value' => 10.0],
                ['month' => '2026-02', 'value' => 5.0],
                ['month' => '2026-03', 'value' => 0.0],
            ])
            ->and(collect($result->projection)->pluck('value')->all())->toBe([0.0, 0.0, 0.0])
            ->and($result->nextMonthDemand)->toBe(0)
            ->and($result->suggestedReorderQuantity)->toBe(0);
    });

    it('floors the suggested reorder quantity at zero when the projection is below current stock', function () {
        $result = projectFrom([10, 12, 14, 16], currentStock: 25, horizon: 1);

        expect($result->nextMonthDemand)->toBe(18)
            ->and($result->suggestedReorderQuantity)->toBe(0);
    });

    it('computes the suggested reorder quantity as the ceiling of next-month demand minus current stock', function () {
        $result = projectFrom([10, 12, 14, 16], currentStock: 10, horizon: 1);

        expect($result->nextMonthDemand)->toBe(18)
            ->and($result->suggestedReorderQuantity)->toBe(8);
    });

    it('zero-fills the history and projection month labels starting at the given first month', function () {
        $result = projectFrom([1, 2], currentStock: 0, horizon: 1);

        expect($result->history)->toBe([
            ['month' => '2026-01', 'consumed' => 1],
            ['month' => '2026-02', 'consumed' => 2],
        ])
            ->and($result->projection[0]['month'])->toBe('2026-03');
    });
});
