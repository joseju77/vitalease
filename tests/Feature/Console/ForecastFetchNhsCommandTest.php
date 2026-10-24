<?php

use App\Services\Inventory\Forecasting\Evaluation\NhsEpdClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/**
 * Every test talks to a faked NHS API with invented practices and substances;
 * Http::preventStrayRequests() makes any unfaked call fail the test.
 */
beforeEach(function () {
    $this->extractDirectory = sys_get_temp_dir().'/vitalease-fetch-nhs-'.uniqid();

    // No real sleeping between retries.
    app()->instance(NhsEpdClient::class, new NhsEpdClient(maxAttempts: 3, baseDelayMilliseconds: 0));
});

afterEach(function () {
    File::deleteDirectory($this->extractDirectory);
});

/**
 * @param  list<array<string, mixed>>  $records
 * @return array<string, mixed>
 */
function nhsEnvelope(array $records, bool $truncated = false): array
{
    return ['success' => true, 'result' => ['result' => ['records' => $records, 'records_truncated' => $truncated]]];
}

/**
 * Fake the NHS API. Eligibility queries list the given practice totals;
 * monthly queries return one row per requested practice.
 *
 * @param  array<string, int>  $totals
 */
function fakeNhsApi(array $totals, ?Closure $monthResponse = null): void
{
    Http::preventStrayRequests();
    Http::fake(function (Request $request) use ($totals, $monthResponse) {
        $sql = (string) $request->data()['sql'];

        if (! str_contains($sql, 'BNF_CHEMICAL_SUBSTANCE')) {
            return Http::response(nhsEnvelope(array_map(
                fn (string $code, int $items): array => ['PRACTICE_CODE' => $code, 'ITEMS' => (string) $items],
                array_keys($totals),
                array_values($totals),
            )));
        }

        if ($monthResponse !== null) {
            return $monthResponse($request, $sql);
        }

        preg_match_all("/'([^']+)'/", $sql, $matches);

        return Http::response(nhsEnvelope(array_map(
            fn (string $code): array => ['PRACTICE_CODE' => $code, 'BNF_CHEMICAL_SUBSTANCE' => 'Invented substance', 'ITEMS' => '12.0'],
            $matches[1],
        )));
    });
}

/**
 * @return array<string, int>
 */
function eligibleNhsTotals(int $count = 30): array
{
    $totals = [];

    for ($i = 1; $i <= $count; $i++) {
        $totals[sprintf('P%05d', $i)] = 5000;
    }

    return $totals;
}

/**
 * @return list<string>
 */
function sampledNhsPractices(string $directory): array
{
    return json_decode((string) file_get_contents($directory.'/sample.json'), true)['practices'];
}

describe('extract', function () {
    it('writes the sample, monthly files and manifest', function () {
        fakeNhsApi(eligibleNhsTotals());

        $this->artisan('forecast:fetch-nhs', ['--from' => '2021-01', '--to' => '2021-03', '--practices' => 3, '--path' => $this->extractDirectory])
            ->assertSuccessful();

        $manifest = json_decode((string) file_get_contents($this->extractDirectory.'/manifest.json'), true);

        expect(array_map('strval', array_keys($manifest['months'])))->toBe(['202101', '202102', '202103'])
            ->and($manifest['unit'])->toBe('ITEMS')
            ->and($manifest['months']['202101']['sha256'])->toBe(hash_file('sha256', $this->extractDirectory.'/months/202101.csv'))
            ->and($manifest['extract_sha256'])->toMatch('/^[0-9a-f]{64}$/')
            ->and(sampledNhsPractices($this->extractDirectory))->toHaveCount(3);

        $lines = file($this->extractDirectory.'/months/202101.csv', FILE_IGNORE_NEW_LINES);

        expect($lines[0])->toBe('practice_code,bnf_chemical_substance,items')
            ->and($lines)->toHaveCount(4)
            ->and($lines[1])->toEndWith(',"Invented substance",12');
    });

    it('sends the month resource id and only the sampled practices', function () {
        fakeNhsApi(eligibleNhsTotals());

        $this->artisan('forecast:fetch-nhs', ['--from' => '2021-01', '--to' => '2021-02', '--practices' => 3, '--path' => $this->extractDirectory])
            ->assertSuccessful();

        $sample = sampledNhsPractices($this->extractDirectory);
        $monthly = Http::recorded(fn (Request $request): bool => str_contains((string) $request->data()['sql'], 'BNF_CHEMICAL_SUBSTANCE'));

        expect($monthly->map(fn (array $pair): string => $pair[0]->data()['resource_id'])->values()->all())
            ->toBe(['EPD_SNOMED_202101', 'EPD_SNOMED_202102']);

        foreach ($monthly as [$request]) {
            preg_match_all("/'([^']+)'/", (string) $request->data()['sql'], $matches);

            expect($matches[1])->toBe($sample)
                ->and($request->data()['sql'])->toContain('GROUP BY PRACTICE_CODE, BNF_CHEMICAL_SUBSTANCE')
                ->and($request->data()['sql'])->not->toContain('HAVING');
        }
    });

    it('requests the eligibility totals from the first and last month', function () {
        fakeNhsApi(eligibleNhsTotals());

        $this->artisan('forecast:fetch-nhs', ['--from' => '2021-01', '--to' => '2021-03', '--practices' => 3, '--path' => $this->extractDirectory])
            ->assertSuccessful();

        $totals = Http::recorded(fn (Request $request): bool => ! str_contains((string) $request->data()['sql'], 'BNF_CHEMICAL_SUBSTANCE'));

        expect($totals->map(fn (array $pair): string => $pair[0]->data()['resource_id'])->values()->all())
            ->toBe(['EPD_SNOMED_202101', 'EPD_SNOMED_202103']);
    });
});

describe('sampling', function () {
    it('picks the same practices for the same seed and other practices for another seed', function () {
        fakeNhsApi(eligibleNhsTotals());
        $other = $this->extractDirectory.'-other';
        $another = $this->extractDirectory.'-another';

        $this->artisan('forecast:fetch-nhs', ['--from' => '2021-01', '--to' => '2021-01', '--practices' => 5, '--seed' => 11, '--path' => $this->extractDirectory])->assertSuccessful();
        $this->artisan('forecast:fetch-nhs', ['--from' => '2021-01', '--to' => '2021-01', '--practices' => 5, '--seed' => 11, '--path' => $other])->assertSuccessful();
        $this->artisan('forecast:fetch-nhs', ['--from' => '2021-01', '--to' => '2021-01', '--practices' => 5, '--seed' => 12, '--path' => $another])->assertSuccessful();

        $sample = sampledNhsPractices($this->extractDirectory);

        expect(sampledNhsPractices($other))->toBe($sample)
            ->and(sampledNhsPractices($another))->not->toBe($sample)
            ->and($sample)->toHaveCount(5)
            ->and($sample)->toBe(collect($sample)->sort()->values()->all());

        File::deleteDirectory($other);
        File::deleteDirectory($another);
    });

    it('never samples the unidentified practice, low-volume practices or malformed codes', function () {
        fakeNhsApi([
            '-' => 900000,
            'LOW001' => 10,
            "X'; DROP" => 5000,
            'good one' => 5000,
            'AAA111' => 5000,
            'BBB222' => 5000,
        ]);

        $this->artisan('forecast:fetch-nhs', ['--from' => '2021-01', '--to' => '2021-01', '--practices' => 2, '--path' => $this->extractDirectory])
            ->assertSuccessful();

        $sql = Http::recorded()->map(fn (array $pair): string => (string) $pair[0]->data()['sql'])->implode("\n");

        expect(sampledNhsPractices($this->extractDirectory))->toBe(['AAA111', 'BBB222'])
            ->and($sql)->not->toContain("'-'", 'LOW001', 'DROP', 'good one');
    });

    it('excludes the unidentified practice in the eligibility query itself', function () {
        fakeNhsApi(eligibleNhsTotals());

        $this->artisan('forecast:fetch-nhs', ['--from' => '2021-01', '--to' => '2021-01', '--practices' => 3, '--path' => $this->extractDirectory])
            ->assertSuccessful();

        $eligibility = Http::recorded(fn (Request $request): bool => ! str_contains((string) $request->data()['sql'], 'BNF_CHEMICAL_SUBSTANCE'))->first()[0];

        expect($eligibility->data()['sql'])->toContain("PRACTICE_CODE <> '-'")
            ->and($eligibility->data()['sql'])->not->toContain('HAVING');
    });

    it('fails when too few practices are eligible', function () {
        fakeNhsApi(['AAA111' => 5000, 'BBB222' => 50]);

        $this->artisan('forecast:fetch-nhs', ['--from' => '2021-01', '--to' => '2021-01', '--practices' => 2, '--path' => $this->extractDirectory])
            ->expectsOutputToContain('Only 1 practices are eligible')
            ->assertFailed();
    });
});

describe('resume', function () {
    it('requests only the missing months on a rerun', function () {
        fakeNhsApi(eligibleNhsTotals());
        $arguments = ['--from' => '2021-01', '--to' => '2021-05', '--practices' => 3, '--path' => $this->extractDirectory];

        $this->artisan('forecast:fetch-nhs', $arguments)->assertSuccessful();

        // 2 eligibility queries + 5 monthly queries
        expect(Http::recorded())->toHaveCount(7);

        // Lose two months: only those are fetched again, and no eligibility query is repeated.
        unlink($this->extractDirectory.'/months/202102.csv');
        unlink($this->extractDirectory.'/months/202104.csv');
        $before = Http::recorded()->count();

        $this->artisan('forecast:fetch-nhs', $arguments)->assertSuccessful();

        $newRequests = Http::recorded()->slice($before)->map(fn (array $pair): string => $pair[0]->data()['resource_id'])->values()->all();

        expect($newRequests)->toBe(['EPD_SNOMED_202102', 'EPD_SNOMED_202104']);
    });

    it('sends no request at all when the extract is complete', function () {
        fakeNhsApi(eligibleNhsTotals());
        $arguments = ['--from' => '2021-01', '--to' => '2021-03', '--practices' => 3, '--path' => $this->extractDirectory];

        $this->artisan('forecast:fetch-nhs', $arguments)->assertSuccessful();
        $before = Http::recorded()->count();

        $this->artisan('forecast:fetch-nhs', $arguments)->assertSuccessful();

        expect(Http::recorded()->count())->toBe($before);
    });

    it('refuses to reuse an extract made with different parameters', function () {
        fakeNhsApi(eligibleNhsTotals());

        $this->artisan('forecast:fetch-nhs', ['--from' => '2021-01', '--to' => '2021-02', '--practices' => 3, '--path' => $this->extractDirectory])->assertSuccessful();
        $before = Http::recorded()->count();

        $this->artisan('forecast:fetch-nhs', ['--from' => '2021-01', '--to' => '2021-02', '--practices' => 4, '--path' => $this->extractDirectory])
            ->expectsOutputToContain('different parameters')
            ->assertFailed();

        expect(Http::recorded()->count())->toBe($before);
    });
});

describe('failures', function () {
    it('retries a transient 5xx and then succeeds', function () {
        $failures = 0;
        fakeNhsApi(eligibleNhsTotals(), function (Request $request, string $sql) use (&$failures) {
            if ($request->data()['resource_id'] === 'EPD_SNOMED_202102' && $failures < 2) {
                $failures++;

                return Http::response('upstream error', 503);
            }

            preg_match_all("/'([^']+)'/", $sql, $matches);

            return Http::response(nhsEnvelope(array_map(
                fn (string $code): array => ['PRACTICE_CODE' => $code, 'BNF_CHEMICAL_SUBSTANCE' => 'Invented substance', 'ITEMS' => '1'],
                $matches[1],
            )));
        });

        $this->artisan('forecast:fetch-nhs', ['--from' => '2021-01', '--to' => '2021-02', '--practices' => 3, '--path' => $this->extractDirectory])
            ->assertSuccessful();

        $attempts = Http::recorded(fn (Request $request): bool => $request->data()['resource_id'] === 'EPD_SNOMED_202102'
            && str_contains((string) $request->data()['sql'], 'BNF_CHEMICAL_SUBSTANCE'));

        expect($failures)->toBe(2)
            ->and($attempts)->toHaveCount(3)
            ->and(file_exists($this->extractDirectory.'/months/202102.csv'))->toBeTrue();
    });

    it('fails with a clear error after the bounded number of retries', function () {
        fakeNhsApi(eligibleNhsTotals(), fn () => Http::response('upstream error', 503));

        $this->artisan('forecast:fetch-nhs', ['--from' => '2021-01', '--to' => '2021-01', '--practices' => 3, '--path' => $this->extractDirectory])
            ->expectsOutputToContain('The NHS API request failed')
            ->assertFailed();

        $attempts = Http::recorded(fn (Request $request): bool => str_contains((string) $request->data()['sql'], 'BNF_CHEMICAL_SUBSTANCE'));

        expect($attempts)->toHaveCount(3)
            ->and(file_exists($this->extractDirectory.'/months/202101.csv'))->toBeFalse();
    });

    it('does not retry a client error', function () {
        fakeNhsApi(eligibleNhsTotals(), fn () => Http::response('bad request', 400));

        $this->artisan('forecast:fetch-nhs', ['--from' => '2021-01', '--to' => '2021-01', '--practices' => 3, '--path' => $this->extractDirectory])
            ->assertFailed();

        $attempts = Http::recorded(fn (Request $request): bool => str_contains((string) $request->data()['sql'], 'BNF_CHEMICAL_SUBSTANCE'));

        expect($attempts)->toHaveCount(1);
    });

    it('fails on a truncated response and writes nothing for that month', function () {
        fakeNhsApi(eligibleNhsTotals(), fn () => Http::response(nhsEnvelope([['PRACTICE_CODE' => 'P00001', 'BNF_CHEMICAL_SUBSTANCE' => 'x', 'ITEMS' => '1']], truncated: true)));

        $this->artisan('forecast:fetch-nhs', ['--from' => '2021-01', '--to' => '2021-01', '--practices' => 3, '--path' => $this->extractDirectory])
            ->expectsOutputToContain('truncated')
            ->assertFailed();

        expect(file_exists($this->extractDirectory.'/months/202101.csv'))->toBeFalse();
    });

    it('fails when the API does not report success', function () {
        fakeNhsApi(eligibleNhsTotals(), fn () => Http::response(['success' => false, 'error' => ['message' => 'nope']]));

        $this->artisan('forecast:fetch-nhs', ['--from' => '2021-01', '--to' => '2021-01', '--practices' => 3, '--path' => $this->extractDirectory])
            ->expectsOutputToContain('did not report success')
            ->assertFailed();
    });

    it('fails on a response without records', function () {
        fakeNhsApi(eligibleNhsTotals(), fn () => Http::response(['success' => true, 'result' => ['result' => ['fields' => []]]]));

        $this->artisan('forecast:fetch-nhs', ['--from' => '2021-01', '--to' => '2021-01', '--practices' => 3, '--path' => $this->extractDirectory])
            ->expectsOutputToContain('no records')
            ->assertFailed();
    });

    it('rejects a response row with an invalid practice code', function () {
        fakeNhsApi(eligibleNhsTotals(), fn () => Http::response(nhsEnvelope([['PRACTICE_CODE' => 'bad code!', 'BNF_CHEMICAL_SUBSTANCE' => 'x', 'ITEMS' => '1']])));

        $this->artisan('forecast:fetch-nhs', ['--from' => '2021-01', '--to' => '2021-01', '--practices' => 3, '--path' => $this->extractDirectory])
            ->expectsOutputToContain('invalid practice code')
            ->assertFailed();
    });

    it('rejects a response row for a practice outside the sample', function () {
        fakeNhsApi(eligibleNhsTotals(), fn () => Http::response(nhsEnvelope([['PRACTICE_CODE' => 'ZZZ999', 'BNF_CHEMICAL_SUBSTANCE' => 'x', 'ITEMS' => '1']])));

        $this->artisan('forecast:fetch-nhs', ['--from' => '2021-01', '--to' => '2021-01', '--practices' => 3, '--path' => $this->extractDirectory])
            ->expectsOutputToContain('outside the requested sample')
            ->assertFailed();
    });
});

describe('input validation', function () {
    it('rejects invalid options without sending any request', function (array $options, string $message) {
        fakeNhsApi(eligibleNhsTotals());

        $this->artisan('forecast:fetch-nhs', [...$options, '--path' => $this->extractDirectory])
            ->expectsOutputToContain($message)
            ->assertFailed();

        Http::assertNothingSent();
    })->with([
        'zero practices' => [['--practices' => 0], '--practices'],
        'non-numeric seed' => [['--seed' => 'abc'], '--seed'],
        'negative minimum' => [['--min-practice-items' => -1], '--min-practice-items'],
        'malformed month' => [['--from' => '2021-13'], 'Invalid month'],
        'reversed range' => [['--from' => '2021-05', '--to' => '2021-01'], 'must not be after'],
    ]);

    it('rejects an invalid practice code or resource id before composing SQL', function () {
        $client = new NhsEpdClient;

        expect(fn () => $client->monthSql('EPD_SNOMED_202101', ["AB'; DROP TABLE x; --"]))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $client->monthSql('EPD_SNOMED_202101', []))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $client->monthSql('EPD_SNOMED_2021; DROP', ['AAA111']))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $client->practiceTotalsSql('other_table'))->toThrow(InvalidArgumentException::class)
            ->and($client->monthSql('EPD_SNOMED_202101', ['AAA111', 'BBB222']))->toContain("IN ('AAA111', 'BBB222')");
    });
});
