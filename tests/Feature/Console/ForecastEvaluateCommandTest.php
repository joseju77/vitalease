<?php

use App\Services\Inventory\Forecasting\Evaluation\EvaluationReportWriter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/**
 * Offline command: it must never touch the network, the real NHS extract or
 * the committed results; all output goes to temporary directories.
 */
const FORECAST_FIXTURE_EXTRACT = __DIR__.'/../../Fixtures/nhs-epd/segments';

beforeEach(function () {
    Http::preventStrayRequests();
    $this->outputRoot = sys_get_temp_dir().'/vitalease-evaluate-'.uniqid();
    $this->secondOutputRoot = $this->outputRoot.'-second';
});

afterEach(function () {
    File::deleteDirectory($this->outputRoot);
    File::deleteDirectory($this->secondOutputRoot);
});

/**
 * @return array<string, string> file name => contents
 */
function evaluationFilesIn(string $directory): array
{
    $files = [];

    foreach (glob($directory.'/*') as $path) {
        $files[basename($path)] = (string) file_get_contents($path);
    }

    ksort($files);

    return $files;
}

describe('synthetic dataset', function () {
    it('writes the result files without needing any external data', function () {
        $this->artisan('forecast:evaluate', ['--dataset' => 'synthetic', '--output' => $this->outputRoot])->assertSuccessful();

        $directory = $this->outputRoot.'/06-forecast-synthetic';
        $files = evaluationFilesIn($directory);

        expect(array_keys($files))->toBe(['run.json', 'series.csv', 'summary.json', 'summary.md'])
            ->and(is_dir($this->outputRoot.'/05-forecast-nhs'))->toBeFalse();

        $rows = array_map('str_getcsv', explode("\n", trim($files['series.csv'])));
        $header = array_shift($rows);
        $indexes = array_column($rows, 0);
        $sortedIndexes = $indexes;
        sort($sortedIndexes, SORT_STRING);

        expect($rows)->toHaveCount(240)
            ->and($header)->toContain('index', 'class', 'seasonality', 'n_months', 'adi', 'cv2', 'selects_linear_margin_0', 'selects_linear_margin_5', 'selects_linear_margin_10')
            ->and($indexes)->toBe($sortedIndexes)
            ->and($indexes[0])->toBe('syn-constant-01');
    });

    it('is byte-identical across two runs except for run.json', function () {
        $this->artisan('forecast:evaluate', ['--dataset' => 'synthetic', '--output' => $this->outputRoot])->assertSuccessful();
        $this->artisan('forecast:evaluate', ['--dataset' => 'synthetic', '--output' => $this->secondOutputRoot])->assertSuccessful();

        $first = evaluationFilesIn($this->outputRoot.'/06-forecast-synthetic');
        $second = evaluationFilesIn($this->secondOutputRoot.'/06-forecast-synthetic');

        $runMetadata = json_decode($first['run.json'], true);
        unset($first['run.json'], $second['run.json']);

        expect($first)->toBe($second)
            ->and(array_keys($runMetadata))->toBe(['run_at', 'php_version']);
    });

    it('reports its parameters and the selection sensitivity in the summary', function () {
        $this->artisan('forecast:evaluate', ['--dataset' => 'synthetic', '--output' => $this->outputRoot, '--seed' => 99])->assertSuccessful();

        $summary = json_decode((string) file_get_contents($this->outputRoot.'/06-forecast-synthetic/summary.json'), true);

        expect($summary['dataset'])->toBe('synthetic')
            ->and($summary['attribution'])->toBeNull()
            ->and($summary['parameters']['seed'])->toBe(99)
            ->and($summary['parameters']['horizons'])->toBe([1, 3])
            ->and($summary['parameters']['selection_margins'])->toBe([0, 0.05, 0.1])
            ->and($summary['parameters']['in_app_margin'])->toBe(0.05);
    });

    it('honours the horizons option', function () {
        $this->artisan('forecast:evaluate', ['--dataset' => 'synthetic', '--output' => $this->outputRoot, '--horizons' => '1'])->assertSuccessful();

        $header = strtok((string) file_get_contents($this->outputRoot.'/06-forecast-synthetic/series.csv'), "\n");

        expect($header)->toContain('mae_linear_trend_h1')
            ->and($header)->not->toContain('_h3');
    });
});

describe('nhs dataset', function () {
    it('evaluates an extract with attribution and without practice codes or substance names', function () {
        $this->artisan('forecast:evaluate', ['--dataset' => 'nhs', '--path' => FORECAST_FIXTURE_EXTRACT, '--output' => $this->outputRoot])->assertSuccessful();

        $files = evaluationFilesIn($this->outputRoot.'/05-forecast-nhs');
        $summary = json_decode($files['summary.json'], true);
        $rows = array_map('str_getcsv', explode("\n", trim($files['series.csv'])));
        array_shift($rows);

        expect($summary['attribution'])->toBe(EvaluationReportWriter::ATTRIBUTION)
            ->and($files['summary.md'])->toContain('Contains NHSBSA data, OGL 3.0')
            ->and($summary['provenance']['extract_sha256'])->toBe('fixture-extract-checksum')
            ->and(array_column($rows, 0))->toBe(['nhs-00001', 'nhs-00002', 'nhs-00003']);

        foreach (['AAA111', 'BBB222', 'CCC333', 'DDD444', 'Alpha', 'Base', 'Charlie', 'Bravo', 'Delta'] as $private) {
            foreach (['series.csv', 'summary.json', 'summary.md'] as $file) {
                expect($files[$file])->not->toContain($private);
            }
        }

        expect(is_dir($this->outputRoot.'/06-forecast-synthetic'))->toBeFalse();
    });

    it('is byte-identical across two runs except for run.json', function () {
        $this->artisan('forecast:evaluate', ['--dataset' => 'nhs', '--path' => FORECAST_FIXTURE_EXTRACT, '--output' => $this->outputRoot])->assertSuccessful();
        $this->artisan('forecast:evaluate', ['--dataset' => 'nhs', '--path' => FORECAST_FIXTURE_EXTRACT, '--output' => $this->secondOutputRoot])->assertSuccessful();

        $first = evaluationFilesIn($this->outputRoot.'/05-forecast-nhs');
        $second = evaluationFilesIn($this->secondOutputRoot.'/05-forecast-nhs');
        unset($first['run.json'], $second['run.json']);

        expect($first)->toBe($second);
    });

    it('fails and names the fetch command when there is no extract', function (string $dataset) {
        $this->artisan('forecast:evaluate', ['--dataset' => $dataset, '--path' => $this->outputRoot.'/missing-extract', '--output' => $this->outputRoot])
            ->expectsOutputToContain('make forecast-fetch-nhs')
            ->assertFailed();

        expect(is_dir($this->outputRoot.'/05-forecast-nhs'))->toBeFalse()
            ->and(is_dir($this->outputRoot.'/06-forecast-synthetic'))->toBeFalse();
    })->with(['nhs', 'all']);

    it('fails when the extract does not match its manifest', function () {
        $extract = $this->outputRoot.'/extract';
        File::copyDirectory(FORECAST_FIXTURE_EXTRACT, $extract);
        file_put_contents($extract.'/months/202105.csv', "practice_code,bnf_chemical_substance,items\nAAA111,Alpha,999\n");

        $this->artisan('forecast:evaluate', ['--dataset' => 'nhs', '--path' => $extract, '--output' => $this->outputRoot.'/out'])
            ->expectsOutputToContain('does not match the manifest')
            ->assertFailed();
    });
});

describe('input validation', function () {
    it('rejects invalid options', function (array $options, string $message) {
        $this->artisan('forecast:evaluate', [...$options, '--output' => $this->outputRoot])
            ->expectsOutputToContain($message)
            ->assertFailed();

        expect(is_dir($this->outputRoot))->toBeFalse();
    })->with([
        'unknown dataset' => [['--dataset' => 'other'], '--dataset'],
        'minimum train below 2' => [['--dataset' => 'synthetic', '--min-train' => 1], '--min-train'],
        'non-numeric seed' => [['--dataset' => 'synthetic', '--seed' => 'x'], '--seed'],
        'bad horizons' => [['--dataset' => 'synthetic', '--horizons' => '1,a'], '--horizons'],
        'zero horizon' => [['--dataset' => 'synthetic', '--horizons' => '0'], '--horizons'],
    ]);
});
