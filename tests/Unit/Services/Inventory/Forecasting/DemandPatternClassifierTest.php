<?php

use App\Services\Inventory\Forecasting\DemandPattern;
use App\Services\Inventory\Forecasting\DemandPatternClassifier;
use Random\Engine\Mt19937;
use Random\Randomizer;

describe('Syntetos-Boylan class', function () {
    it('classifies a regular series as smooth', function () {
        // ADI 1 and CV² of [10,12,11,10,13,11] is about 0.009
        $pattern = DemandPatternClassifier::classify([10, 12, 11, 10, 13, 11]);

        expect($pattern->classification)->toBe(DemandPattern::SMOOTH)
            ->and($pattern->adi)->toBe(1.0)
            ->and($pattern->cv2)->toBeLessThan(0.49);
    });

    it('classifies constant demand as smooth with zero variability', function () {
        $pattern = DemandPatternClassifier::classify([7, 7, 7, 7]);

        expect($pattern->classification)->toBe(DemandPattern::SMOOTH)
            ->and($pattern->cv2)->toBe(0.0);
    });

    it('classifies frequent demand with very variable sizes as erratic', function () {
        $pattern = DemandPatternClassifier::classify([1, 50, 2, 60, 1, 40]);

        expect($pattern->classification)->toBe(DemandPattern::ERRATIC)
            ->and($pattern->adi)->toBe(1.0)
            ->and($pattern->cv2)->toBeGreaterThan(0.49);
    });

    it('classifies sporadic demand of stable size as intermittent', function () {
        // 8 months, 4 non-zero: ADI 2, identical sizes -> CV² 0
        $pattern = DemandPatternClassifier::classify([0, 10, 0, 10, 0, 10, 0, 10]);

        expect($pattern->classification)->toBe(DemandPattern::INTERMITTENT)
            ->and($pattern->adi)->toBe(2.0)
            ->and($pattern->cv2)->toBe(0.0);
    });

    it('classifies sporadic demand of variable size as lumpy', function () {
        // 12 months, 4 non-zero: ADI 3, sizes 1, 30, 5, 40 -> CV² well above 0.49
        $pattern = DemandPatternClassifier::classify([0, 1, 0, 0, 30, 0, 0, 5, 0, 0, 0, 40]);

        expect($pattern->classification)->toBe(DemandPattern::LUMPY)
            ->and($pattern->adi)->toBe(3.0)
            ->and($pattern->cv2)->toBeGreaterThan(0.49);
    });

    it('sends an ADI exactly at 1.32 to the high side', function () {
        // 33 months with 25 non-zero equal values: ADI = 1.32, CV² = 0
        $atCutoff = [...array_fill(0, 25, 5), ...array_fill(0, 8, 0)];
        // 32 months with 25 non-zero: ADI = 1.28
        $belowCutoff = [...array_fill(0, 25, 5), ...array_fill(0, 7, 0)];

        expect(DemandPatternClassifier::classify($atCutoff)->classification)->toBe(DemandPattern::INTERMITTENT)
            ->and(DemandPatternClassifier::classify($belowCutoff)->classification)->toBe(DemandPattern::SMOOTH);
    });

    it('sends a CV² exactly at 0.49 to the high side', function () {
        // [17, 3]: mean 10, population variance 49 -> CV² = 0.49 exactly. [16, 4]: variance 36 -> 0.36
        expect(DemandPatternClassifier::classify([17, 3])->classification)->toBe(DemandPattern::ERRATIC)
            ->and(DemandPatternClassifier::classify([16, 4])->classification)->toBe(DemandPattern::SMOOTH);
    });

    it('reports no_demand without an exception when nothing was consumed', function () {
        $pattern = DemandPatternClassifier::classify([0, 0, 0, 0]);

        expect($pattern->classification)->toBe(DemandPattern::NO_DEMAND)
            ->and($pattern->adi)->toBeNull()
            ->and($pattern->cv2)->toBeNull();
    });

    it('handles a single non-zero month with zero variability', function () {
        $pattern = DemandPatternClassifier::classify([0, 0, 5, 0]);

        expect($pattern->classification)->toBe(DemandPattern::INTERMITTENT)
            ->and($pattern->adi)->toBe(4.0)
            ->and($pattern->cv2)->toBe(0.0);
    });

    it('rounds adi and cv2 to four decimals in its array form', function () {
        // 7 months, 3 non-zero (2, 4, 9): ADI = 7/3, mean 5, variance (9+1+16)/3 -> CV² = 26/75
        $array = DemandPatternClassifier::classify([2, 0, 4, 0, 0, 9, 0])->toArray();

        expect($array)->toBe([
            'class' => 'intermittent',
            'adi' => 2.3333,
            'cv2' => 0.3467,
            'seasonality' => 'not_assessed',
        ]);
    });
});

describe('seasonality', function () {
    it('is not assessed with fewer than 24 months', function () {
        $series = array_map(fn (int $t): int => (int) round(50 + 20 * sin(2 * M_PI * $t / 12)), range(0, 22));

        expect(DemandPatternClassifier::classify($series)->seasonality)->toBe(DemandPattern::NOT_ASSESSED);
    });

    it('is assessed from exactly 24 months', function () {
        // With only two periods the lag-12 estimate is about 0.37, below the 2/sqrt(24) = 0.41 threshold,
        // so the flag has low power at the minimum length; what matters here is that it is assessed.
        $series = array_map(fn (int $t): int => (int) round(50 + 20 * sin(2 * M_PI * $t / 12)), range(0, 23));

        expect(DemandPatternClassifier::classify($series)->seasonality)->toBe(DemandPattern::NOT_SEASONAL);
    });

    it('flags a 48-month period-12 sine as seasonal', function () {
        $series = array_map(fn (int $t): int => (int) round(50 + 20 * sin(2 * M_PI * $t / 12)), range(0, 47));

        expect(DemandPatternClassifier::classify($series)->seasonality)->toBe(DemandPattern::SEASONAL);
    });

    it('still flags seasonality on top of a linear trend', function () {
        $series = array_map(fn (int $t): int => (int) round(20 + 2 * $t + 15 * sin(2 * M_PI * $t / 12)), range(0, 47));

        expect(DemandPatternClassifier::classify($series)->seasonality)->toBe(DemandPattern::SEASONAL);
    });

    it('does not flag a pure trend or a constant series', function () {
        $trend = array_map(fn (int $t): int => 10 + 3 * $t, range(0, 35));

        expect(DemandPatternClassifier::classify($trend)->seasonality)->toBe(DemandPattern::NOT_SEASONAL)
            ->and(DemandPatternClassifier::classify(array_fill(0, 30, 9))->seasonality)->toBe(DemandPattern::NOT_SEASONAL);
    });

    it('does not flag unstructured noise', function () {
        $randomizer = new Randomizer(new Mt19937(7));
        $noise = array_map(fn (): int => $randomizer->getInt(20, 60), range(0, 35));

        expect(DemandPatternClassifier::classify($noise)->seasonality)->toBe(DemandPattern::NOT_SEASONAL);
    });
});
