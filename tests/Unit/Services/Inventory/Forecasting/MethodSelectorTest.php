<?php

use App\Services\Inventory\Forecasting\MethodSelection;
use App\Services\Inventory\Forecasting\MethodSelector;

describe('history length', function () {
    it('reports insufficient data below two months', function () {
        $selection = MethodSelector::choose(1, [], []);

        expect($selection->method)->toBeNull()
            ->and($selection->reason)->toBe(MethodSelection::REASON_INSUFFICIENT_DATA)
            ->and($selection->evaluatedPoints)->toBe(0);
    });

    it('falls back to the mean with short_history at 8 months even when linear is perfect', function () {
        $selection = MethodSelector::choose(8, [0.0, 0.0, 0.0], [5.0, 5.0, 5.0]);

        expect($selection->method)->toBe('historical_mean')
            ->and($selection->reason)->toBe(MethodSelection::REASON_SHORT_HISTORY)
            ->and($selection->isLinear())->toBeFalse();
    });

    it('applies the rule from 9 months', function () {
        $selection = MethodSelector::choose(9, [0.0, 0.0, 0.0], [5.0, 5.0, 5.0]);

        expect($selection->method)->toBe('linear_trend')
            ->and($selection->reason)->toBe(MethodSelection::REASON_LINEAR_BEATS_MEAN)
            ->and($selection->isLinear())->toBeTrue();
    });

    it('still reports the errors observed during a short history', function () {
        $selection = MethodSelector::choose(5, [2.0, 4.0], [6.0, 8.0]);

        expect($selection->evaluatedPoints)->toBe(2)
            ->and($selection->linearMae)->toBe(3.0)
            ->and($selection->meanMae)->toBe(7.0);
    });
});

describe('margin', function () {
    it('chooses linear at exactly 0.95 of the mean MAE', function () {
        $selection = MethodSelector::choose(12, [9.5, 9.5, 9.5], [10.0, 10.0, 10.0]);

        expect($selection->method)->toBe('linear_trend');
    });

    it('chooses the mean at 0.96 of the mean MAE', function () {
        $selection = MethodSelector::choose(12, [9.6, 9.6, 9.6], [10.0, 10.0, 10.0]);

        expect($selection->method)->toBe('historical_mean')
            ->and($selection->reason)->toBe(MethodSelection::REASON_MEAN_BEATS_LINEAR);
    });

    it('compares the means of the errors, not individual origins', function () {
        // linear MAE (0 + 0 + 9) / 3 = 3 against mean MAE 10: linear wins overall despite one bad origin
        expect(MethodSelector::choose(12, [0.0, 0.0, 9.0], [10.0, 10.0, 10.0])->method)->toBe('linear_trend');
    });

    it('lets the margin be overridden for the sensitivity analysis', function () {
        $linear = [9.7, 9.7, 9.7];
        $mean = [10.0, 10.0, 10.0];

        expect(MethodSelector::choose(12, $linear, $mean, 0.0)->method)->toBe('linear_trend')
            ->and(MethodSelector::choose(12, $linear, $mean)->method)->toBe('historical_mean')
            ->and(MethodSelector::choose(12, $linear, $mean, 0.10)->method)->toBe('historical_mean')
            ->and(MethodSelector::choose(12, [9.0, 9.0, 9.0], $mean, 0.10)->method)->toBe('linear_trend');
    });

    it('uses a fixed default margin of 5 percent', function () {
        expect(MethodSelector::DEFAULT_MARGIN)->toBe(0.05);
    });
});

describe('ties and degenerate errors', function () {
    it('chooses the mean on a tie', function () {
        $selection = MethodSelector::choose(12, [4.0, 4.0, 4.0], [4.0, 4.0, 4.0]);

        expect($selection->method)->toBe('historical_mean')
            ->and($selection->reason)->toBe(MethodSelection::REASON_MEAN_BEATS_LINEAR);
    });

    it('chooses the mean when the mean has zero error', function () {
        $selection = MethodSelector::choose(12, [0.0, 0.0, 0.0], [0.0, 0.0, 0.0]);

        expect($selection->method)->toBe('historical_mean');
    });

    it('requires at least three evaluated origins', function () {
        expect(MethodSelector::choose(12, [0.0, 0.0], [5.0, 5.0])->method)->toBe('historical_mean')
            ->and(MethodSelector::choose(12, [0.0, 0.0, 0.0], [5.0, 5.0, 5.0])->method)->toBe('linear_trend');
    });

    it('requires identical origins for both methods', function () {
        MethodSelector::choose(12, [1.0, 2.0, 3.0], [1.0, 2.0]);
    })->throws(InvalidArgumentException::class);
});
