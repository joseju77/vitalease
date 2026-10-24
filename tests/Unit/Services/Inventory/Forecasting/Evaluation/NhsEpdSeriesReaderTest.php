<?php

use App\Services\Inventory\Forecasting\Evaluation\EvaluationSeries;
use App\Services\Inventory\Forecasting\Evaluation\NhsEpdSeriesReader;

/**
 * Invented fixture: 20 months (202101 to 202208) for four invented practices.
 * AAA111 is active every month (substance Alpha is absent in months 3 and 11,
 * Beta has a single non-zero month, Zeta only zero rows); BBB222 is inactive in
 * month 18 (longest run 17); CCC333 is active in months 1-18 and 20 (longest
 * run 18); DDD444 is active in months 1-4 and 8-20 (longest run 13).
 */
const NHS_FIXTURE_SEGMENTS = __DIR__.'/../../../../../Fixtures/nhs-epd/segments';

/**
 * @param  list<EvaluationSeries>  $series
 * @return array<string, list<int>>
 */
function nhsSeriesByKey(array $series): array
{
    $byKey = [];

    foreach ($series as $one) {
        $byKey[$one->key] = $one->values;
    }

    return $byKey;
}

describe('missing versus zero', function () {
    it('zero-fills a month where an active practice did not report the substance', function () {
        $report = (new NhsEpdSeriesReader)->read(NHS_FIXTURE_SEGMENTS);
        $alpha = nhsSeriesByKey($report['series'])['AAA111|Alpha'];

        // Months 1..20: 10, 12, (absent), 14, 5 x5, (absent at 11), 5 x9
        expect($alpha)->toHaveCount(20)
            ->and($alpha[2])->toBe(0)
            ->and($alpha[10])->toBe(0)
            ->and($alpha)->toBe([10, 12, 0, 14, 5, 5, 5, 5, 5, 5, 0, 5, 5, 5, 5, 5, 5, 5, 5, 5]);
    });

    it('does not zero-fill the months a practice did not report at all: segments split at the gap', function () {
        $series = nhsSeriesByKey((new NhsEpdSeriesReader)->read(NHS_FIXTURE_SEGMENTS, 12)['series']);

        // DDD444 is inactive in months 5-7: only the longest segment (months 8-20, values 7..19) is used,
        // the first segment of value 10 is discarded and no zeros are inserted for the gap.
        expect($series['DDD444|Delta'])->toBe(range(7, 19));
    });

    it('ignores activity after the longest run', function () {
        $series = nhsSeriesByKey((new NhsEpdSeriesReader)->read(NHS_FIXTURE_SEGMENTS)['series']);

        // CCC333 reports 99 in month 20, after an inactive month 19: it must not leak into the 18-month run.
        expect($series['CCC333|Charlie'])->toBe(range(1, 18));
    });
});

describe('length boundary', function () {
    it('drops a 17-month segment and keeps an 18-month one', function () {
        $report = (new NhsEpdSeriesReader)->read(NHS_FIXTURE_SEGMENTS);
        $keys = array_keys(nhsSeriesByKey($report['series']));

        expect($keys)->not->toContain('BBB222|Bravo')
            ->and($keys)->toContain('CCC333|Charlie');
    });

    it('keeps the 17-month segment when the minimum length is lowered', function () {
        $series = nhsSeriesByKey((new NhsEpdSeriesReader)->read(NHS_FIXTURE_SEGMENTS, 17)['series']);

        expect($series['BBB222|Bravo'])->toHaveCount(17);
    });
});

describe('report', function () {
    it('counts kept and dropped series by reason', function () {
        $report = (new NhsEpdSeriesReader)->read(NHS_FIXTURE_SEGMENTS);

        expect($report['months'])->toBe(20)
            ->and($report['practices'])->toBe(4)
            ->and($report['candidates'])->toBe(7)
            ->and($report['kept'])->toBe(3)
            ->and(array_keys(nhsSeriesByKey($report['series'])))->toBe(['AAA111|Alpha', 'AAA111|Base', 'CCC333|Charlie'])
            ->and($report['dropped'])->toBe(['too_short' => 2, 'all_zero' => 1, 'single_non_zero' => 1])
            ->and($report['extract_sha256'])->toBe('fixture-extract-checksum');
    });

    it('counts candidates with at least two non-zero months at the 12, 18 and 24 month thresholds', function () {
        $report = (new NhsEpdSeriesReader)->read(NHS_FIXTURE_SEGMENTS);

        // Alpha and Base (20 months), Bravo (17), Charlie (18), Delta (13)
        expect($report['counts_by_min_length'])->toBe([12 => 5, 18 => 3, 24 => 0]);
    });
});

describe('integrity', function () {
    it('fails when there is no manifest', function () {
        (new NhsEpdSeriesReader)->read(sys_get_temp_dir().'/vitalease-no-extract-'.uniqid());
    })->throws(RuntimeException::class, 'No NHS extract found');

    it('fails when a month file does not match the manifest hash', function () {
        $directory = sys_get_temp_dir().'/vitalease-tampered-'.uniqid();
        mkdir($directory.'/months', 0775, true);
        file_put_contents($directory.'/months/202101.csv', "practice_code,bnf_chemical_substance,items\nAAA111,Alpha,1\n");
        file_put_contents($directory.'/manifest.json', json_encode(['months' => ['202101' => ['sha256' => str_repeat('0', 64)]]]));

        try {
            expect(fn () => (new NhsEpdSeriesReader)->read($directory))->toThrow(RuntimeException::class, 'does not match the manifest');
        } finally {
            array_map('unlink', glob($directory.'/months/*'));
            rmdir($directory.'/months');
            unlink($directory.'/manifest.json');
            rmdir($directory);
        }
    });
});
