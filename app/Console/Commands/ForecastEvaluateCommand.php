<?php

namespace App\Console\Commands;

use App\Services\Inventory\Forecasting\Evaluation\EvaluationAggregator;
use App\Services\Inventory\Forecasting\Evaluation\EvaluationReportWriter;
use App\Services\Inventory\Forecasting\Evaluation\EvaluationRunner;
use App\Services\Inventory\Forecasting\Evaluation\EvaluationSeries;
use App\Services\Inventory\Forecasting\Evaluation\NhsEpdSeriesReader;
use App\Services\Inventory\Forecasting\Evaluation\SyntheticSeriesGenerator;
use App\Services\Inventory\Forecasting\MethodSelector;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;

#[Signature('forecast:evaluate {--dataset=all : nhs, synthetic or all} {--path= : NHS extract directory (defaults to storage/app/private/datasets/nhs-epd)} {--output=docs/evaluation/results : Base directory for the result folders} {--min-train=6 : Minimum training months of every backtest origin} {--horizons=1,3 : Comma-separated forecast horizons in months} {--seed=20261024 : Seed of the synthetic series}')]
#[Description('Backtest the demand forecasting methods offline on the local NHS extract and/or synthetic series')]
class ForecastEvaluateCommand extends Command
{
    private const NHS_DIRECTORY = '05-forecast-nhs';

    private const SYNTHETIC_DIRECTORY = '06-forecast-synthetic';

    /**
     * Execute the console command.
     */
    public function handle(
        EvaluationRunner $runner,
        EvaluationAggregator $aggregator,
        EvaluationReportWriter $writer,
        NhsEpdSeriesReader $reader,
        SyntheticSeriesGenerator $generator,
    ): int {
        $dataset = (string) $this->option('dataset');
        $minTrain = filter_var($this->option('min-train'), FILTER_VALIDATE_INT);
        $seed = filter_var($this->option('seed'), FILTER_VALIDATE_INT);
        $horizons = $this->parseHorizons((string) $this->option('horizons'));

        if (! in_array($dataset, ['nhs', 'synthetic', 'all'], true)) {
            $this->components->error('The --dataset option must be nhs, synthetic or all.');

            return self::FAILURE;
        }

        if ($minTrain === false || $minTrain < 2) {
            $this->components->error('The --min-train option must be an integer of at least 2.');

            return self::FAILURE;
        }

        if ($seed === false) {
            $this->components->error('The --seed option must be an integer.');

            return self::FAILURE;
        }

        if ($horizons === null) {
            $this->components->error('The --horizons option must be a comma-separated list of positive integers.');

            return self::FAILURE;
        }

        $path = $this->option('path');
        $extract = is_string($path) && $path !== '' ? rtrim($path, '/') : storage_path('app/private/datasets/nhs-epd');
        $output = $this->resolveOutput((string) $this->option('output'));

        if ($dataset !== 'synthetic' && ! is_file($extract.'/manifest.json')) {
            $this->components->error("No NHS extract found in {$extract}. Run `make forecast-fetch-nhs` (forecast:fetch-nhs) first.");

            return self::FAILURE;
        }

        $parameters = [
            'min_train' => $minTrain,
            'horizons' => $horizons,
            'selection_margins' => EvaluationRunner::MARGINS,
            'in_app_margin' => MethodSelector::DEFAULT_MARGIN,
            'min_history_for_selection' => MethodSelector::MIN_HISTORY_FOR_SELECTION,
            'min_evaluated_origins' => MethodSelector::MIN_EVALUATED_ORIGINS,
        ];

        try {
            if ($dataset !== 'synthetic') {
                $report = $reader->read($extract);
                $series = $report['series'];
                unset($report['series']);

                $this->evaluateDataset('nhs', $series, 'nhs', $output.'/'.self::NHS_DIRECTORY, $parameters, [
                    ...$this->extractProvenance($extract),
                    ...$report,
                ], $runner, $aggregator, $writer);
            }

            if ($dataset !== 'nhs') {
                $this->evaluateDataset('synthetic', $generator->generate($seed), 'syn', $output.'/'.self::SYNTHETIC_DIRECTORY, [...$parameters, 'seed' => $seed], [
                    'patterns' => SyntheticSeriesGenerator::PATTERNS,
                    'replicates' => SyntheticSeriesGenerator::REPLICATES,
                    'length_months' => SyntheticSeriesGenerator::LENGTH,
                    'seasonal_length_months' => SyntheticSeriesGenerator::SEASONAL_LENGTH,
                ], $runner, $aggregator, $writer);
            }
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  'nhs'|'synthetic'  $dataset
     * @param  list<EvaluationSeries>  $series
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $provenance
     */
    private function evaluateDataset(
        string $dataset,
        array $series,
        string $prefix,
        string $directory,
        array $parameters,
        array $provenance,
        EvaluationRunner $runner,
        EvaluationAggregator $aggregator,
        EvaluationReportWriter $writer,
    ): void {
        /** @var list<int> $horizons */
        $horizons = $parameters['horizons'];

        $writer->begin($directory, $horizons);

        $streamed = (function () use ($runner, $series, $horizons, $parameters, $prefix, $writer): \Generator {
            foreach ($runner->run($series, $horizons, $parameters['min_train'], $prefix) as $evaluation) {
                $writer->appendSeries($evaluation);

                yield $evaluation;
            }
        })();

        $aggregates = $aggregator->summarize($streamed);

        $writer->finish($dataset, $parameters, $provenance, $aggregates);

        $this->components->info("{$aggregates['series']} {$dataset} series evaluated; results written to {$directory}.");
    }

    /**
     * Query parameters and checksum of the extract, taken from its manifest.
     * The SQL and practice codes stay out of the committed output.
     *
     * @return array<string, mixed>
     */
    private function extractProvenance(string $extract): array
    {
        try {
            $manifest = json_decode((string) file_get_contents($extract.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException("The manifest in {$extract} is not valid JSON.");
        }

        $months = is_array($manifest['months'] ?? null) ? $manifest['months'] : [];
        ksort($months);

        return [
            'endpoint' => $manifest['endpoint'] ?? null,
            'unit' => $manifest['unit'] ?? null,
            'sampling' => is_array($manifest['parameters'] ?? null) ? $manifest['parameters'] : null,
            'first_resource_id' => $months === [] ? null : ($months[array_key_first($months)]['resource_id'] ?? null),
            'last_resource_id' => $months === [] ? null : ($months[array_key_last($months)]['resource_id'] ?? null),
        ];
    }

    /**
     * @return list<int>|null
     */
    private function parseHorizons(string $value): ?array
    {
        $horizons = [];

        foreach (explode(',', $value) as $part) {
            $horizon = filter_var(trim($part), FILTER_VALIDATE_INT);

            if ($horizon === false || $horizon < 1) {
                return null;
            }

            $horizons[] = $horizon;
        }

        $horizons = array_values(array_unique($horizons));
        sort($horizons);

        return $horizons;
    }

    private function resolveOutput(string $output): string
    {
        $output = rtrim($output, '/');

        return str_starts_with($output, '/') ? $output : base_path($output);
    }
}
