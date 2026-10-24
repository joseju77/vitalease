<?php

namespace App\Console\Commands;

use App\Services\Inventory\Forecasting\Evaluation\NhsEpdExtractor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;

#[Signature('forecast:fetch-nhs {--from=2020-11 : First month to extract (YYYY-MM)} {--to=2026-08 : Last month to extract (YYYY-MM)} {--practices=20 : Number of GP practices to sample} {--seed=20261024 : Seed for the deterministic practice sample} {--min-practice-items=1000 : Minimum items a practice must prescribe in the first and last month} {--path= : Extract directory (defaults to storage/app/private/datasets/nhs-epd)}')]
#[Description('Download a sample of the NHS English Prescribing Dataset into a local, git-ignored extract (network; resumable)')]
class ForecastFetchNhsCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(NhsEpdExtractor $extractor): int
    {
        $practices = filter_var($this->option('practices'), FILTER_VALIDATE_INT);
        $seed = filter_var($this->option('seed'), FILTER_VALIDATE_INT);
        $minPracticeItems = filter_var($this->option('min-practice-items'), FILTER_VALIDATE_INT);

        if ($practices === false || $practices < 1) {
            $this->components->error('The --practices option must be a positive integer.');

            return self::FAILURE;
        }

        if ($seed === false) {
            $this->components->error('The --seed option must be an integer.');

            return self::FAILURE;
        }

        if ($minPracticeItems === false || $minPracticeItems < 0) {
            $this->components->error('The --min-practice-items option must be a non-negative integer.');

            return self::FAILURE;
        }

        $path = $this->option('path');
        $directory = is_string($path) && $path !== '' ? rtrim($path, '/') : storage_path('app/private/datasets/nhs-epd');

        try {
            $summary = $extractor->extract(
                $directory,
                (string) $this->option('from'),
                (string) $this->option('to'),
                $practices,
                $seed,
                $minPracticeItems,
                fn (string $message) => $this->components->info($message),
            );
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("NHS extract ready: {$summary['months']} months, {$summary['practices']} practices ({$summary['requested']} requests, {$summary['skipped']} months already present).");
        $this->components->twoColumnDetail('Extract directory', $directory);
        $this->components->twoColumnDetail('Extract sha256', $summary['extract_sha256']);

        return self::SUCCESS;
    }
}
