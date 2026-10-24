<?php

use App\Services\Inventory\Forecasting\DemandPattern;
use App\Services\Inventory\Forecasting\DemandPatternClassifier;
use App\Services\Inventory\Forecasting\Evaluation\EvaluationSeries;
use App\Services\Inventory\Forecasting\Evaluation\SyntheticSeriesGenerator;

/**
 * @return list<EvaluationSeries>
 */
function syntheticSeriesOf(string $pattern): array
{
    return array_values(array_filter(
        (new SyntheticSeriesGenerator)->generate(),
        fn (EvaluationSeries $series): bool => $series->source === $pattern,
    ));
}

describe('shape', function () {
    it('generates 8 patterns times 30 replicates of non-negative integers', function () {
        $series = (new SyntheticSeriesGenerator)->generate();

        expect($series)->toHaveCount(240);

        foreach ($series as $one) {
            expect($one->values)->toHaveCount($one->source === 'seasonal' ? 48 : 36);

            foreach ($one->values as $value) {
                expect($value)->toBeInt()->toBeGreaterThanOrEqual(0);
            }
        }
    });

    it('names series by pattern and replicate number', function () {
        $keys = array_map(fn (EvaluationSeries $series): string => $series->key, (new SyntheticSeriesGenerator)->generate());

        expect($keys[0])->toBe('syn-constant-01')
            ->and($keys[29])->toBe('syn-constant-30')
            ->and($keys[30])->toBe('syn-pure_trend-01')
            ->and($keys[239])->toBe('syn-level_shift-30')
            ->and(array_unique($keys))->toHaveCount(240);
    });

    it('builds a pure trend as an exact straight line plus rounding', function () {
        foreach (syntheticSeriesOf('pure_trend') as $series) {
            $steps = array_map(fn (int $t): int => $series->values[$t + 1] - $series->values[$t], range(0, 34));

            // slope between 0.5 and 2.5 per month, rounded to integers, so every step is within 0..3
            expect(min($steps))->toBeGreaterThanOrEqual(0)
                ->and(max($steps))->toBeLessThanOrEqual(3);
        }
    });
});

describe('determinism', function () {
    it('produces byte-identical series for the same seed', function () {
        expect(json_encode((new SyntheticSeriesGenerator)->generate(123)))
            ->toBe(json_encode((new SyntheticSeriesGenerator)->generate(123)));
    });

    it('produces different series for a different seed', function () {
        expect(json_encode((new SyntheticSeriesGenerator)->generate(1)))
            ->not->toBe(json_encode((new SyntheticSeriesGenerator)->generate(2)));
    });

    it('derives each sub-seed only from the seed, the pattern and the replicate', function () {
        // First 8 hex characters of sha256("20261024:lumpy:5"), computed independently
        expect(SyntheticSeriesGenerator::subSeed(20261024, 'lumpy', 5))
            ->toBe((int) hexdec(substr(hash('sha256', '20261024:lumpy:5'), 0, 8)))
            ->and(SyntheticSeriesGenerator::subSeed(20261024, 'lumpy', 5))->not->toBe(SyntheticSeriesGenerator::subSeed(20261024, 'lumpy', 6))
            ->and(SyntheticSeriesGenerator::subSeed(20261024, 'lumpy', 5))->not->toBe(SyntheticSeriesGenerator::subSeed(20261024, 'erratic', 5));
    });

    it('generates a series independently of the others, so adding or reordering patterns shifts nothing', function () {
        $generator = new SyntheticSeriesGenerator;
        $all = $generator->generate(555);
        $positionOfLumpy05 = array_search('lumpy', SyntheticSeriesGenerator::PATTERNS, true) * 30 + 4;

        // Generating other series first, in any order, must not change a given series
        $generator->generateOne(555, 'level_shift', 30);
        $generator->generateOne(555, 'constant', 1);

        expect($generator->generateOne(555, 'lumpy', 5)->values)->toBe($all[$positionOfLumpy05]->values)
            ->and($generator->generateOne(555, 'lumpy', 5)->key)->toBe('syn-lumpy-05');
    });
});

describe('patterns', function () {
    it('yields the expected class for the majority of replicates', function (string $pattern, string $expectedClass) {
        $matching = array_filter(
            syntheticSeriesOf($pattern),
            fn (EvaluationSeries $series): bool => DemandPatternClassifier::classify($series->values)->classification === $expectedClass,
        );

        expect(count($matching))->toBeGreaterThan(15);
    })->with([
        'constant' => ['constant', DemandPattern::SMOOTH],
        'pure trend' => ['pure_trend', DemandPattern::SMOOTH],
        'trend with noise' => ['trend_noise', DemandPattern::SMOOTH],
        'seasonal' => ['seasonal', DemandPattern::SMOOTH],
        'erratic' => ['erratic', DemandPattern::ERRATIC],
        'intermittent' => ['intermittent', DemandPattern::INTERMITTENT],
        'lumpy' => ['lumpy', DemandPattern::LUMPY],
        'level shift' => ['level_shift', DemandPattern::SMOOTH],
    ]);

    it('flags the seasonal pattern as seasonal and a constant one as not seasonal for the majority', function () {
        $seasonal = array_filter(
            syntheticSeriesOf('seasonal'),
            fn (EvaluationSeries $series): bool => DemandPatternClassifier::classify($series->values)->seasonality === DemandPattern::SEASONAL,
        );
        $constant = array_filter(
            syntheticSeriesOf('constant'),
            fn (EvaluationSeries $series): bool => DemandPatternClassifier::classify($series->values)->seasonality === DemandPattern::NOT_SEASONAL,
        );

        expect(count($seasonal))->toBeGreaterThan(15)
            ->and(count($constant))->toBeGreaterThan(15);
    });

    it('keeps the constant pattern perfectly flat', function () {
        foreach (syntheticSeriesOf('constant') as $series) {
            expect(array_unique($series->values))->toHaveCount(1);
        }
    });
});
