<?php

use App\Services\Inventory\Forecasting\Forecaster;
use App\Services\Inventory\Forecasting\HistoricalMeanForecaster;
use App\Services\Inventory\Forecasting\LinearTrendForecaster;
use App\Services\Inventory\Forecasting\NaiveForecaster;
use App\Services\Inventory\Forecasting\RollingOriginBacktester;
use App\Services\Inventory\Forecasting\SeasonalNaiveForecaster;

describe('origins', function () {
    it('uses expanding origins from the minimum training size up to n - h', function () {
        $result = (new RollingOriginBacktester)->backtest(range(1, 10), [new NaiveForecaster], 1);

        expect($result->origins)->toBe([6, 7, 8, 9])
            ->and($result->evaluated())->toBe(4)
            ->and($result->actuals)->toBe([7, 8, 9, 10]);
    });

    it('scores the target h - 1 months after the last training month', function () {
        $result = (new RollingOriginBacktester)->backtest(range(1, 10), [new NaiveForecaster], 3);

        // origins 6 and 7 -> targets are series[8] and series[9]
        expect($result->origins)->toBe([6, 7])
            ->and($result->actuals)->toBe([9, 10]);
    });

    it('returns zero origins when the series is shorter than the minimum train plus the horizon', function () {
        $result = (new RollingOriginBacktester)->backtest(range(1, 8), [new NaiveForecaster], 3);

        expect($result->evaluated())->toBe(0)
            ->and($result->mae('naive'))->toBeNull()
            ->and($result->rmse('naive'))->toBeNull();
    });

    it('honours a custom minimum train size', function () {
        $result = (new RollingOriginBacktester)->backtest(range(1, 6), [new NaiveForecaster], 1, minTrain: 3);

        expect($result->origins)->toBe([3, 4, 5]);
    });

    it('starts all methods on the same origins, after the longest minimum training length', function () {
        $result = (new RollingOriginBacktester)->backtest(range(1, 14), [new LinearTrendForecaster, new SeasonalNaiveForecaster], 1);

        expect($result->origins)->toBe([12, 13])
            ->and($result->forecasts('linear_trend'))->toHaveCount(2)
            ->and($result->forecasts('seasonal_naive_12'))->toHaveCount(2);
    });

    it('rejects a horizon below one', function () {
        (new RollingOriginBacktester)->backtest(range(1, 10), [new NaiveForecaster], 0);
    })->throws(InvalidArgumentException::class);
});

describe('errors', function () {
    it('computes hand-computed MAE and RMSE per method', function () {
        $result = (new RollingOriginBacktester)->backtest(
            range(1, 10),
            [new NaiveForecaster, new HistoricalMeanForecaster, new LinearTrendForecaster],
            1,
        );

        // actuals 7..10. Naive predicts 6..9 -> every error is 1.
        expect($result->mae('naive'))->toBe(1.0)
            ->and($result->rmse('naive'))->toBe(1.0)
            // Mean of the first 6, 7, 8, 9 values is 3.5, 4, 4.5, 5 -> errors 3.5, 4, 4.5, 5 -> MAE 4.25
            ->and($result->mae('historical_mean'))->toBe(4.25)
            ->and($result->absoluteErrors('historical_mean'))->toEqualWithDelta([3.5, 4.0, 4.5, 5.0], 1e-12)
            // A straight line is extrapolated exactly
            ->and($result->mae('linear_trend'))->toBe(0.0);
    });

    it('rounds forecasts and error summaries to four decimals', function () {
        // Origin 6: mean of [0,0,1,0,0,0] is 1/6; actual 5 -> error 4.83333...
        $result = (new RollingOriginBacktester)->backtest([0, 0, 1, 0, 0, 0, 5], [new HistoricalMeanForecaster], 1);

        expect($result->forecasts('historical_mean'))->toBe([0.1667])
            ->and($result->mae('historical_mean'))->toBe(4.8333);
    });

    it('clamps forecasts at zero before scoring', function () {
        $series = [50, 40, 30, 20, 10, 5, 0, 0, 0, 0];

        // The raw line through the training data goes below zero at the last origin.
        expect((new LinearTrendForecaster)->forecast(array_slice($series, 0, 8), 1)[0])->toBeLessThan(0.0);

        $result = (new RollingOriginBacktester)->backtest($series, [new LinearTrendForecaster], 1);
        $errors = $result->absoluteErrors('linear_trend');

        expect(min($result->forecasts('linear_trend')))->toBeGreaterThanOrEqual(0.0)
            // actual is 0 at the last origin, so the clamped forecast gives exactly zero error there
            ->and($errors[array_key_last($errors)])->toBe(0.0);
    });

    it('exposes empty results for an unknown method', function () {
        $result = (new RollingOriginBacktester)->backtest(range(1, 10), [new NaiveForecaster], 1);

        expect($result->mae('unknown'))->toBeNull()
            ->and($result->forecasts('unknown'))->toBe([])
            ->and($result->absoluteErrors('unknown'))->toBe([]);
    });
});

describe('leakage', function () {
    it('shows every forecaster only the months before the origin, never the target or later', function () {
        $spy = new class implements Forecaster
        {
            /** @var list<list<int>> */
            public array $seenTrainSets = [];

            public function name(): string
            {
                return 'spy';
            }

            public function minimumTrainingLength(): int
            {
                return 1;
            }

            public function forecast(array $train, int $horizon): array
            {
                $this->seenTrainSets[] = $train;

                return array_fill(0, $horizon, 0.0);
            }
        };
        // Unique values, so a leaked target or later month is detectable by value.
        $series = [101, 102, 103, 104, 105, 106, 107, 108, 109, 110, 111, 112];

        $result = (new RollingOriginBacktester)->backtest($series, [$spy], 3);

        expect($spy->seenTrainSets)->toHaveCount(count($result->origins));

        foreach ($result->origins as $position => $origin) {
            $target = $series[$origin + 2];

            expect($spy->seenTrainSets[$position])->toBe(array_slice($series, 0, $origin))
                ->and($spy->seenTrainSets[$position])->not->toContain($target)
                ->and(max($spy->seenTrainSets[$position]))->toBeLessThan($target);
        }
    });

    it('produces identical forecasts when a later value of the series changes', function () {
        $series = [3, 8, 2, 9, 4, 7, 5, 10, 6, 12];
        $changedLast = [...array_slice($series, 0, 9), 500];
        $methods = [new LinearTrendForecaster, new HistoricalMeanForecaster, new NaiveForecaster];

        $original = (new RollingOriginBacktester)->backtest($series, $methods, 1);
        $changed = (new RollingOriginBacktester)->backtest($changedLast, $methods, 1);

        foreach (['linear_trend', 'historical_mean', 'naive'] as $method) {
            expect($changed->forecasts($method))->toBe($original->forecasts($method));
        }

        expect($changed->actuals)->not->toBe($original->actuals);
    });
});
