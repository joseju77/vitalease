<?php

use App\Services\Inventory\Forecasting\ErrorMetrics;

describe('MAE and RMSE', function () {
    it('computes the hand-computed errors', function () {
        // |2-3| = 1, |4-2| = 2 -> MAE 1.5; squares 1 and 4 -> RMSE sqrt(2.5)
        expect(ErrorMetrics::mae([2, 4], [3, 2]))->toEqualWithDelta(1.5, 1e-12)
            ->and(ErrorMetrics::rmse([2, 4], [3, 2]))->toEqualWithDelta(sqrt(2.5), 1e-12);
    });

    it('is zero for a perfect forecast', function () {
        expect(ErrorMetrics::mae([3, 3], [3, 3]))->toBe(0.0)
            ->and(ErrorMetrics::rmse([3, 3], [3, 3]))->toBe(0.0);
    });

    it('returns null when there is nothing to score', function () {
        expect(ErrorMetrics::mae([], []))->toBeNull()
            ->and(ErrorMetrics::rmse([], []))->toBeNull();
    });

    it('rejects series of different length', function () {
        ErrorMetrics::mae([1, 2], [1]);
    })->throws(InvalidArgumentException::class);
});

describe('MASE', function () {
    it('scales by the in-sample one-step naive MAE', function () {
        // series [1, 3, 2, 6]: |2| + |1| + |4| = 7 over 3 steps -> scale 7/3
        expect(ErrorMetrics::mase(7 / 3, [1, 3, 2, 6]))->toEqualWithDelta(1.0, 1e-12)
            ->and(ErrorMetrics::mase(1.4, [1, 3, 2, 6]))->toEqualWithDelta(0.6, 1e-12);
    });

    it('is skipped when the scale is zero or the series is too short', function () {
        expect(ErrorMetrics::mase(2.0, [5, 5, 5]))->toBeNull()
            ->and(ErrorMetrics::mase(2.0, [5]))->toBeNull();
    });
});

describe('relative MAE and ties', function () {
    it('divides by the mean MAE and is undefined when that is zero', function () {
        expect(ErrorMetrics::relativeMae(1.5, 3.0))->toEqualWithDelta(0.5, 1e-12)
            ->and(ErrorMetrics::relativeMae(1.5, 0.0))->toBeNull()
            ->and(ErrorMetrics::relativeMae(0.0, 0.0))->toBeNull();
    });

    it('counts differences below 1e-9 as ties', function () {
        expect(ErrorMetrics::isTie(1.0, 1.0 + 1e-10))->toBeTrue()
            ->and(ErrorMetrics::isTie(1.0, 1.0 + 1e-8))->toBeFalse();
    });
});
