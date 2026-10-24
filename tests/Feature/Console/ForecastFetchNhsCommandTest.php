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

function nhsSql(Request $request): string
{
    return (string) ($request->data()['sql'] ?? '');
}

/**
 * Field list of a monthly resource, as returned by datastore_search.
 *
 * @return array<string, mixed>
 */
function nhsFieldsResponse(bool $currentSchema): array
{
    $ids = $currentSchema
        ? ['PRACTICE_CODE', 'BNF_PRESENTATION_CODE', 'BNF_CHEMICAL_SUBSTANCE', 'BNF_CHEMICAL_SUBSTANCE_CODE', 'ADQ_USAGE', 'ITEMS']
        : ['PRACTICE_CODE', 'BNF_CODE', 'BNF_CHEMICAL_SUBSTANCE', 'CHEMICAL_SUBSTANCE_BNF_DESCR', 'ADQUSAGE', 'ITEMS'];

    return ['success' => true, 'result' => ['fields' => array_map(fn (string $id): array => ['id' => $id, 'type' => 'text'], $ids), 'records' => []]];
}

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
        $sql = nhsSql($request);

        if ($sql === '') {
            return Http::response(nhsFieldsResponse(currentSchema: (int) substr($request->data()['resource_id'], -6) >= 202503));
        }

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
            fn (string $code): array => ['PRACTICE_CODE' => $code, 'BNF_CHEMICAL_SUBSTANCE' => '0212000B0', 'ITEMS' => '12.0'],
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
            ->and($lines[1])->toEndWith(',0212000B0,12');
    });

    it('sends the month resource id and only the sampled practices', function () {
        fakeNhsApi(eligibleNhsTotals());

        $this->artisan('forecast:fetch-nhs', ['--from' => '2021-01', '--to' => '2021-02', '--practices' => 3, '--path' => $this->extractDirectory])
            ->assertSuccessful();

        $sample = sampledNhsPractices($this->extractDirectory);
        $monthly = Http::recorded(fn (Request $request): bool => str_contains(nhsSql($request), 'BNF_CHEMICAL_SUBSTANCE'));

        expect($monthly->map(fn (array $pair): string => $pair[0]->data()['resource_id'])->values()->all())
            ->toBe(['EPD_SNOMED_202101', 'EPD_SNOMED_202102']);

        foreach ($monthly as [$request]) {
            preg_match_all("/'([^']+)'/", nhsSql($request), $matches);

            expect($matches[1])->toBe($sample)
                ->and(nhsSql($request))->toContain('GROUP BY PRACTICE_CODE, BNF_CHEMICAL_SUBSTANCE')
                ->and(nhsSql($request))->not->toContain('HAVING');
        }
    });

    it('requests the eligibility totals from the first and last month', function () {
        fakeNhsApi(eligibleNhsTotals());

        $this->artisan('forecast:fetch-nhs', ['--from' => '2021-01', '--to' => '2021-03', '--practices' => 3, '--path' => $this->extractDirectory])
            ->assertSuccessful();

        $totals = Http::recorded(fn (Request $request): bool => nhsSql($request) !== '' && ! str_contains(nhsSql($request), 'BNF_CHEMICAL_SUBSTANCE'));

        expect($totals->map(fn (array $pair): string => $pair[0]->data()['resource_id'])->values()->all())
            ->toBe(['EPD_SNOMED_202101', 'EPD_SNOMED_202103']);
    });
});

describe('substance column schema', function () {
    /**
     * @return array<string, list<string>>
     */
    function nhsExtractedSubstances(string $directory, string $month): array
    {
        $rows = array_map('str_getcsv', file($directory.'/months/'.$month.'.csv', FILE_IGNORE_NEW_LINES));
        array_shift($rows);
        $bySubstance = [];

        foreach ($rows as [$practice, $substance]) {
            $bySubstance[$practice][] = $substance;
        }

        return $bySubstance;
    }

    /**
     * Fake an API whose old schema returns codes in BNF_CHEMICAL_SUBSTANCE and
     * whose current schema returns names there and codes in the _CODE column.
     */
    function fakeSchemaChangingApi(): void
    {
        fakeNhsApi(eligibleNhsTotals(), function (Request $request, string $sql) {
            // Querying the legacy column after the change would return names.
            $names = (int) substr($request->data()['resource_id'], -6) >= 202503 && ! str_contains($sql, 'BNF_CHEMICAL_SUBSTANCE_CODE AS BNF_CHEMICAL_SUBSTANCE');
            preg_match_all("/'([^']+)'/", $sql, $matches);

            return Http::response(nhsEnvelope(array_map(
                fn (string $code): array => ['PRACTICE_CODE' => $code, 'BNF_CHEMICAL_SUBSTANCE' => $names ? 'Atorvastatin' : '0212000B0', 'ITEMS' => '5'],
                $matches[1],
            )));
        });
    }

    it('extracts the same codes before and after the schema change', function () {
        fakeSchemaChangingApi();

        $this->artisan('forecast:fetch-nhs', ['--from' => '2025-02', '--to' => '2025-03', '--practices' => 3, '--path' => $this->extractDirectory])
            ->assertSuccessful();

        expect(nhsExtractedSubstances($this->extractDirectory, '202503'))->toBe(nhsExtractedSubstances($this->extractDirectory, '202502'))
            ->and(collect(nhsExtractedSubstances($this->extractDirectory, '202503'))->flatten()->unique()->all())->toBe(['0212000B0']);
    });

    it('queries the legacy column up to 202502 and the aliased code column from 202503', function () {
        fakeSchemaChangingApi();

        $this->artisan('forecast:fetch-nhs', ['--from' => '2025-02', '--to' => '2025-03', '--practices' => 3, '--path' => $this->extractDirectory])
            ->assertSuccessful();

        $sql = Http::recorded(fn (Request $request): bool => str_contains(nhsSql($request), 'GROUP BY PRACTICE_CODE,'))
            ->map(fn (array $pair): string => nhsSql($pair[0]))->values();

        expect($sql[0])->toContain('SELECT PRACTICE_CODE, BNF_CHEMICAL_SUBSTANCE, SUM(ITEMS)')
            ->and($sql[0])->toEndWith('GROUP BY PRACTICE_CODE, BNF_CHEMICAL_SUBSTANCE')
            ->and($sql[1])->toContain('SELECT PRACTICE_CODE, BNF_CHEMICAL_SUBSTANCE_CODE AS BNF_CHEMICAL_SUBSTANCE, SUM(ITEMS)')
            ->and($sql[1])->toEndWith('GROUP BY PRACTICE_CODE, BNF_CHEMICAL_SUBSTANCE_CODE');
    });

    it('records the resolved column per month in the manifest', function () {
        fakeSchemaChangingApi();

        $this->artisan('forecast:fetch-nhs', ['--from' => '2025-02', '--to' => '2025-03', '--practices' => 3, '--path' => $this->extractDirectory])
            ->assertSuccessful();

        $manifest = json_decode((string) file_get_contents($this->extractDirectory.'/manifest.json'), true);

        expect($manifest['months']['202502']['substance_column'])->toBe('BNF_CHEMICAL_SUBSTANCE')
            ->and($manifest['months']['202503']['substance_column'])->toBe('BNF_CHEMICAL_SUBSTANCE_CODE');
    });

    it('accepts four-character appliance codes', function () {
        fakeNhsApi(eligibleNhsTotals(), function (Request $request, string $sql) {
            preg_match_all("/'([^']+)'/", $sql, $matches);

            return Http::response(nhsEnvelope(array_map(
                fn (string $code): array => ['PRACTICE_CODE' => $code, 'BNF_CHEMICAL_SUBSTANCE' => '2101', 'ITEMS' => '2'],
                $matches[1],
            )));
        });

        $this->artisan('forecast:fetch-nhs', ['--from' => '2021-01', '--to' => '2021-01', '--practices' => 3, '--path' => $this->extractDirectory])
            ->assertSuccessful();

        expect(collect(nhsExtractedSubstances($this->extractDirectory, '202101'))->flatten()->unique()->all())->toBe(['2101']);
    });

    it('fails loudly when the substance values are names instead of codes', function (string $value) {
        fakeNhsApi(eligibleNhsTotals(), function (Request $request, string $sql) use ($value) {
            preg_match_all("/'([^']+)'/", $sql, $matches);

            return Http::response(nhsEnvelope(array_map(
                fn (string $code): array => ['PRACTICE_CODE' => $code, 'BNF_CHEMICAL_SUBSTANCE' => $value, 'ITEMS' => '5'],
                $matches[1],
            )));
        });

        $this->artisan('forecast:fetch-nhs', ['--from' => '2025-03', '--to' => '2025-03', '--practices' => 3, '--path' => $this->extractDirectory])
            ->expectsOutputToContain('not a BNF chemical substance code')
            ->assertFailed();

        expect(file_exists($this->extractDirectory.'/months/202503.csv'))->toBeFalse();
    })->with(['name' => 'Invented substance', 'empty' => '', 'lowercase code' => '0212000b0', 'too long' => '0212000B0X']);

    it('fails when the resource has no substance column', function () {
        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => match (true) {
            nhsSql($request) === '' => Http::response(['success' => true, 'result' => ['fields' => [['id' => 'PRACTICE_CODE']]]]),
            default => Http::response(nhsEnvelope(array_map(
                fn (string $code, int $items): array => ['PRACTICE_CODE' => $code, 'ITEMS' => (string) $items],
                array_keys(eligibleNhsTotals()),
                array_values(eligibleNhsTotals()),
            ))),
        });

        $this->artisan('forecast:fetch-nhs', ['--from' => '2025-03', '--to' => '2025-03', '--practices' => 3, '--path' => $this->extractDirectory])
            ->expectsOutputToContain('No BNF chemical substance column')
            ->assertFailed();
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

        $sql = Http::recorded()->map(fn (array $pair): string => nhsSql($pair[0]))->implode("\n");

        expect(sampledNhsPractices($this->extractDirectory))->toBe(['AAA111', 'BBB222'])
            ->and($sql)->not->toContain("'-'", 'LOW001', 'DROP', 'good one');
    });

    it('excludes the unidentified practice in the eligibility query itself', function () {
        fakeNhsApi(eligibleNhsTotals());

        $this->artisan('forecast:fetch-nhs', ['--from' => '2021-01', '--to' => '2021-01', '--practices' => 3, '--path' => $this->extractDirectory])
            ->assertSuccessful();

        $eligibility = Http::recorded(fn (Request $request): bool => nhsSql($request) !== '' && ! str_contains(nhsSql($request), 'BNF_CHEMICAL_SUBSTANCE'))->first()[0];

        expect(nhsSql($eligibility))->toContain("PRACTICE_CODE <> '-'")
            ->and(nhsSql($eligibility))->not->toContain('HAVING');
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

        // 2 eligibility queries + 5 field lookups + 5 monthly queries
        expect(Http::recorded())->toHaveCount(12);

        // Lose two months: only those are fetched again, and no eligibility query is repeated.
        unlink($this->extractDirectory.'/months/202102.csv');
        unlink($this->extractDirectory.'/months/202104.csv');
        $before = Http::recorded()->count();

        $this->artisan('forecast:fetch-nhs', $arguments)->assertSuccessful();

        $newRequests = Http::recorded()->slice($before)->filter(fn (array $pair): bool => nhsSql($pair[0]) !== '')->map(fn (array $pair): string => $pair[0]->data()['resource_id'])->values()->all();

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
                fn (string $code): array => ['PRACTICE_CODE' => $code, 'BNF_CHEMICAL_SUBSTANCE' => '0212000B0', 'ITEMS' => '1'],
                $matches[1],
            )));
        });

        $this->artisan('forecast:fetch-nhs', ['--from' => '2021-01', '--to' => '2021-02', '--practices' => 3, '--path' => $this->extractDirectory])
            ->assertSuccessful();

        $attempts = Http::recorded(fn (Request $request): bool => $request->data()['resource_id'] === 'EPD_SNOMED_202102'
            && str_contains(nhsSql($request), 'BNF_CHEMICAL_SUBSTANCE'));

        expect($failures)->toBe(2)
            ->and($attempts)->toHaveCount(3)
            ->and(file_exists($this->extractDirectory.'/months/202102.csv'))->toBeTrue();
    });

    it('fails with a clear error after the bounded number of retries', function () {
        fakeNhsApi(eligibleNhsTotals(), fn () => Http::response('upstream error', 503));

        $this->artisan('forecast:fetch-nhs', ['--from' => '2021-01', '--to' => '2021-01', '--practices' => 3, '--path' => $this->extractDirectory])
            ->expectsOutputToContain('The NHS API request failed')
            ->assertFailed();

        $attempts = Http::recorded(fn (Request $request): bool => str_contains(nhsSql($request), 'BNF_CHEMICAL_SUBSTANCE'));

        expect($attempts)->toHaveCount(3)
            ->and(file_exists($this->extractDirectory.'/months/202101.csv'))->toBeFalse();
    });

    it('does not retry a client error', function () {
        fakeNhsApi(eligibleNhsTotals(), fn () => Http::response('bad request', 400));

        $this->artisan('forecast:fetch-nhs', ['--from' => '2021-01', '--to' => '2021-01', '--practices' => 3, '--path' => $this->extractDirectory])
            ->assertFailed();

        $attempts = Http::recorded(fn (Request $request): bool => str_contains(nhsSql($request), 'BNF_CHEMICAL_SUBSTANCE'));

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
        fakeNhsApi(eligibleNhsTotals(), fn () => Http::response(nhsEnvelope([['PRACTICE_CODE' => 'ZZZ999', 'BNF_CHEMICAL_SUBSTANCE' => '0102000AJ', 'ITEMS' => '1']])));

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
