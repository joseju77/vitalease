<?php

use App\Services\Inventory\Forecasting\HistoricalMeanForecaster;
use App\Services\Inventory\Forecasting\LinearTrendFit;
use App\Services\Inventory\Forecasting\LinearTrendForecaster;
use App\Services\Inventory\Forecasting\MovingAverageForecaster;
use App\Services\Inventory\Forecasting\NaiveForecaster;
use App\Services\Inventory\Forecasting\SeasonalNaiveForecaster;

describe('LinearTrendFit', function () {
    it('matches the hand-computed OLS of a noisy series', function () {
        // [1, 2, 4, 3, 5]: xMean 2, yMean 3, Sxy 9, Sxx 10, Syy 10 -> slope 0.9, intercept 1.2, R² 0.81
        $fit = LinearTrendFit::fromSeries([1, 2, 4, 3, 5]);

        expect($fit->slope)->toEqualWithDelta(0.9, 1e-12)
            ->and($fit->intercept)->toEqualWithDelta(1.2, 1e-12)
            ->and($fit->rSquared)->toEqualWithDelta(0.81, 1e-12)
            ->and($fit->valueAt(5))->toEqualWithDelta(5.7, 1e-12);
    });

    it('fits a constant series with zero slope and a perfect R-squared', function () {
        $fit = LinearTrendFit::fromSeries([5, 5, 5, 5]);

        expect($fit->slope)->toBe(0.0)
            ->and($fit->intercept)->toBe(5.0)
            ->and($fit->rSquared)->toBe(1.0);
    });
});

describe('LinearTrendForecaster', function () {
    it('extrapolates a perfectly linear series', function () {
        expect((new LinearTrendForecaster)->forecast([2, 4, 6, 8], 2))->toEqualWithDelta([10.0, 12.0], 1e-12);
    });

    it('does not clamp: the backtester owns the clamp at zero', function () {
        expect((new LinearTrendForecaster)->forecast([5, 3, 1], 1))->toEqualWithDelta([-1.0], 1e-12);
    });

    it('needs at least two training months', function () {
        (new LinearTrendForecaster)->forecast([4], 1);
    })->throws(InvalidArgumentException::class);

    it('reports its name and minimum training length', function () {
        $forecaster = new LinearTrendForecaster;

        expect($forecaster->name())->toBe('linear_trend')
            ->and($forecaster->minimumTrainingLength())->toBe(2);
    });
});

describe('baseline forecasters', function () {
    it('forecasts the historical mean, the last value and the mean of the last three months', function () {
        $train = [1, 2, 3, 10];

        expect((new HistoricalMeanForecaster)->forecast($train, 2))->toEqualWithDelta([4.0, 4.0], 1e-12)
            ->and((new NaiveForecaster)->forecast($train, 2))->toEqualWithDelta([10.0, 10.0], 1e-12)
            ->and((new MovingAverageForecaster)->forecast($train, 1))->toEqualWithDelta([5.0], 1e-12);
    });

    it('moving average only looks at its window', function () {
        // (20 + 30 + 40) / 3 = 30; the early 1000 must be ignored
        expect((new MovingAverageForecaster)->forecast([1000, 20, 30, 40], 1))->toEqualWithDelta([30.0], 1e-12);
    });

    it('rejects training series that are too short', function (Closure $call) {
        expect($call)->toThrow(InvalidArgumentException::class);
    })->with([
        'mean' => [fn () => (new HistoricalMeanForecaster)->forecast([], 1)],
        'naive' => [fn () => (new NaiveForecaster)->forecast([], 1)],
        'moving average' => [fn () => (new MovingAverageForecaster)->forecast([1, 2], 1)],
        'seasonal naive' => [fn () => (new SeasonalNaiveForecaster)->forecast(range(1, 11), 1)],
    ]);

    it('reports names and minimum training lengths', function () {
        expect((new HistoricalMeanForecaster)->name())->toBe('historical_mean')
            ->and((new HistoricalMeanForecaster)->minimumTrainingLength())->toBe(1)
            ->and((new NaiveForecaster)->name())->toBe('naive')
            ->and((new NaiveForecaster)->minimumTrainingLength())->toBe(1)
            ->and((new MovingAverageForecaster)->name())->toBe('moving_average_3')
            ->and((new MovingAverageForecaster)->minimumTrainingLength())->toBe(3)
            ->and((new SeasonalNaiveForecaster)->name())->toBe('seasonal_naive_12')
            ->and((new SeasonalNaiveForecaster)->minimumTrainingLength())->toBe(12);
    });
});

describe('SeasonalNaiveForecaster', function () {
    it('repeats the value of the same month one year earlier', function () {
        // 24 months 1..24: the next three months mirror months 13, 14 and 15 of the last year (values 13..15)
        expect((new SeasonalNaiveForecaster)->forecast(range(1, 24), 3))->toEqualWithDelta([13.0, 14.0, 15.0], 1e-12);
    });

    it('works with exactly one full year of history', function () {
        expect((new SeasonalNaiveForecaster)->forecast(range(1, 12), 2))->toEqualWithDelta([1.0, 2.0], 1e-12);
    });

    it('is unavailable (not zero) with less than twelve months', function () {
        expect(fn () => (new SeasonalNaiveForecaster)->forecast(range(1, 11), 1))->toThrow(InvalidArgumentException::class);
    });
});
