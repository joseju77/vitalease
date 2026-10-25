<?php

use App\Services\Inventory\MedicationDemandProjection;
use Carbon\CarbonImmutable;

/**
 * @param  list<int>  $series
 * @return array<string, mixed>
 */
function selectedProjectionOf(array $series, int $currentStock = 0, int $horizon = 3): array
{
    return (new MedicationDemandProjection)
        ->projectWithSelection($series, CarbonImmutable::create(2025, 8, 1), $currentStock, $horizon)
        ->toArray();
}

describe('linear chosen', function () {
    it('keeps the linear projection when the trend beats the mean', function () {
        // series[i] = 10 + 4i over 12 months: the line is exact (MAE 0)
        $result = selectedProjectionOf(array_map(fn (int $i): int => 10 + 4 * $i, range(0, 11)), currentStock: 20);

        expect($result['status'])->toBe('ok')
            ->and($result['method'])->toBe('linear_trend')
            ->and($result['method_reason'])->toBe('linear_beats_mean')
            ->and($result['slope'])->toBe(4.0)
            ->and($result['intercept'])->toBe(10.0)
            ->and($result['next_month_demand'])->toBe(58)
            ->and($result['suggested_reorder_quantity'])->toBe(38)
            ->and(array_column($result['projection'], 'value'))->toBe([58.0, 62.0, 66.0])
            ->and($result['projection'][0]['month'])->toBe('2026-08');
    });

    it('reports the backtest of both methods on the same origins', function () {
        $result = selectedProjectionOf(array_map(fn (int $i): int => 10 + 4 * $i, range(0, 11)));

        // Origins 6..11. Mean errors 14, 16, 18, 20, 22, 24 -> MAE 19.
        expect($result['backtest'])->toBe([
            'horizon' => 1,
            'margin' => 0.05,
            'evaluated_points' => 6,
            'linear_trend' => ['mae' => 0.0, 'rmse' => 0.0],
            'historical_mean' => ['mae' => 19.0, 'rmse' => round(sqrt((196 + 256 + 324 + 400 + 484 + 576) / 6), 4)],
        ])->and($result['demand_pattern']['class'])->toBe('smooth');
    });
});

describe('mean chosen', function () {
    it('projects a flat mean with null coefficients when linear loses', function () {
        // [10, 0] x 6: the mean (5) errs by 5 at every origin, the line by more
        $result = selectedProjectionOf(array_merge(...array_fill(0, 6, [10, 0])), currentStock: 3);

        expect($result['method'])->toBe('historical_mean')
            ->and($result['method_reason'])->toBe('mean_beats_linear')
            ->and($result['slope'])->toBeNull()
            ->and($result['intercept'])->toBeNull()
            ->and($result['r_squared'])->toBeNull()
            ->and($result['next_month_demand'])->toBe(5)
            ->and($result['suggested_reorder_quantity'])->toBe(2)
            ->and(array_unique(array_column($result['fitted'], 'value')))->toBe([5.0])
            ->and($result['fitted'])->toHaveCount(12)
            ->and(array_column($result['projection'], 'value'))->toBe([5.0, 5.0, 5.0])
            ->and($result['demand_pattern']['class'])->toBe('intermittent');
    });

    it('rounds the next-month demand up from the mean', function () {
        // [11, 0] x 6 has mean 5.5 -> flat line at 5.5, next month demand ceil(5.5) = 6
        $result = selectedProjectionOf(array_merge(...array_fill(0, 6, [11, 0])), currentStock: 10);

        expect($result['method'])->toBe('historical_mean')
            ->and($result['projection'][0]['value'])->toBe(5.5)
            ->and($result['next_month_demand'])->toBe(6)
            ->and($result['suggested_reorder_quantity'])->toBe(0);
    });
});

describe('history length', function () {
    it('uses the mean with short_history at 8 months even when the trend is exact', function () {
        $result = selectedProjectionOf(array_map(fn (int $i): int => 10 + 4 * $i, range(0, 7)));

        expect($result['method'])->toBe('historical_mean')
            ->and($result['method_reason'])->toBe('short_history');
    });

    it('applies the rule at exactly 9 months, with 3 evaluated origins', function () {
        $result = selectedProjectionOf(array_map(fn (int $i): int => 10 + 4 * $i, range(0, 8)));

        expect($result['method'])->toBe('linear_trend')
            ->and($result['method_reason'])->toBe('linear_beats_mean')
            ->and($result['backtest']['evaluated_points'])->toBe(3);
    });

    it('has no evaluated origins when history is shorter than the minimum training window', function () {
        $result = selectedProjectionOf([3, 4, 5, 6, 7]);

        expect($result['method'])->toBe('historical_mean')
            ->and($result['method_reason'])->toBe('short_history')
            ->and($result['backtest']['evaluated_points'])->toBe(0)
            ->and($result['backtest']['linear_trend']['mae'])->toBeNull()
            ->and($result['next_month_demand'])->toBe(5);
    });

    it('keeps the insufficient-data shape for a single month, with no method or backtest', function () {
        $result = selectedProjectionOf([7], currentStock: 3);

        expect($result['status'])->toBe('insufficient_data')
            ->and($result['method'])->toBeNull()
            ->and($result['method_reason'])->toBeNull()
            ->and($result['backtest'])->toBeNull()
            ->and($result['demand_pattern'])->toBeNull()
            ->and($result['projection'])->toBe([])
            ->and($result['current_stock'])->toBe(3);
    });
});

describe('result contract', function () {
    it('keeps every pre-existing key and adds the new ones', function () {
        $result = selectedProjectionOf(array_map(fn (int $i): int => 10 + 4 * $i, range(0, 11)));

        expect(array_keys($result))->toBe([
            'status', 'history', 'fitted', 'projection', 'slope', 'intercept', 'r_squared',
            'next_month_demand', 'current_stock', 'suggested_reorder_quantity',
            'method', 'method_reason', 'backtest', 'demand_pattern',
        ]);
    });
});
