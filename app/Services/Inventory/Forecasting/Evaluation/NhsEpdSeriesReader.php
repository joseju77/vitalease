<?php

namespace App\Services\Inventory\Forecasting\Evaluation;

use JsonException;
use RuntimeException;

/**
 * Turns a local NHS EPD extract into monthly practice x chemical-substance
 * series.
 *
 * A practice is "active" in a month when the extract has at least one row for
 * it. An absent substance in an active month is zero demand; any month where
 * the practice is not active is missing and is never zero-filled. Each
 * practice contributes its longest contiguous active run (the earliest one on
 * ties). A series is kept when that run is long enough and holds at least two
 * non-zero months.
 */
final class NhsEpdSeriesReader
{
    public const MIN_LENGTH = 18;

    public const SENSITIVITY_LENGTHS = [12, 18, 24];

    /**
     * @return array{
     *     series: list<EvaluationSeries>,
     *     months: int,
     *     practices: int,
     *     candidates: int,
     *     kept: int,
     *     dropped: array{too_short: int, all_zero: int, single_non_zero: int},
     *     counts_by_min_length: array<int, int>,
     *     extract_sha256: string|null
     * }
     */
    public function read(string $directory, int $minimumLength = self::MIN_LENGTH): array
    {
        $manifest = $this->readManifest($directory);
        $months = array_keys($manifest['months']);
        sort($months, SORT_STRING);

        /** @var array<string, array<string, true>> $active practice => month index => true */
        $active = [];
        /** @var array<string, array<string, array<int, int>>> $items practice => substance => month index => items */
        $items = [];

        foreach ($months as $index => $month) {
            foreach ($this->readMonth($directory, (string) $month, $manifest['months'][$month]['sha256'] ?? null) as [$practice, $substance, $value]) {
                $active[$practice][$index] = true;
                $items[$practice][$substance][$index] = ($items[$practice][$substance][$index] ?? 0) + $value;
            }
        }

        ksort($items, SORT_STRING);

        $series = [];
        $candidates = 0;
        $dropped = ['too_short' => 0, 'all_zero' => 0, 'single_non_zero' => 0];
        $countsByMinLength = array_fill_keys(self::SENSITIVITY_LENGTHS, 0);

        foreach ($items as $practice => $substances) {
            [$start, $length] = $this->longestRun(array_keys($active[$practice]));

            ksort($substances, SORT_STRING);

            foreach ($substances as $substance => $byMonth) {
                $candidates++;

                $values = [];
                for ($index = $start; $index < $start + $length; $index++) {
                    $values[] = $byMonth[$index] ?? 0;
                }

                $nonZero = count(array_filter($values, static fn (int $value): bool => $value !== 0));

                if ($nonZero >= 2) {
                    foreach (self::SENSITIVITY_LENGTHS as $threshold) {
                        if ($length >= $threshold) {
                            $countsByMinLength[$threshold]++;
                        }
                    }
                }

                if ($length < $minimumLength) {
                    $dropped['too_short']++;
                } elseif ($nonZero === 0) {
                    $dropped['all_zero']++;
                } elseif ($nonZero === 1) {
                    $dropped['single_non_zero']++;
                } else {
                    $series[] = new EvaluationSeries($practice.'|'.$substance, $values);
                }
            }
        }

        return [
            'series' => $series,
            'months' => count($months),
            'practices' => count($items),
            'candidates' => $candidates,
            'kept' => count($series),
            'dropped' => $dropped,
            'counts_by_min_length' => $countsByMinLength,
            'extract_sha256' => $manifest['extract_sha256'] ?? null,
        ];
    }

    /**
     * @param  list<int>  $indices  Active month indices, ascending.
     * @return array{int, int} Start index and length of the longest contiguous run.
     */
    private function longestRun(array $indices): array
    {
        sort($indices);

        $bestStart = $indices[0];
        $bestLength = 0;
        $runStart = $indices[0];
        $runLength = 0;
        $previous = null;

        foreach ($indices as $index) {
            if ($previous !== null && $index === $previous + 1) {
                $runLength++;
            } else {
                $runStart = $index;
                $runLength = 1;
            }

            if ($runLength > $bestLength) {
                $bestStart = $runStart;
                $bestLength = $runLength;
            }

            $previous = $index;
        }

        return [$bestStart, $bestLength];
    }

    /**
     * @return array{months: array<string, array<string, mixed>>, extract_sha256?: string}
     */
    private function readManifest(string $directory): array
    {
        $path = $directory.'/manifest.json';

        if (! is_file($path)) {
            throw new RuntimeException("No NHS extract found in {$directory}.");
        }

        try {
            $manifest = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException("The manifest in {$directory} is not valid JSON.");
        }

        if (! is_array($manifest) || ! is_array($manifest['months'] ?? null) || $manifest['months'] === []) {
            throw new RuntimeException("The manifest in {$directory} lists no months.");
        }

        return $manifest;
    }

    /**
     * @return iterable<array{string, string, int}>
     */
    private function readMonth(string $directory, string $month, ?string $expectedHash): iterable
    {
        $path = "{$directory}/months/{$month}.csv";

        if (! is_file($path) || $expectedHash === null || hash_file('sha256', $path) !== $expectedHash) {
            throw new RuntimeException("The extract file for month {$month} is missing or does not match the manifest.");
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Cannot read {$path}.");
        }

        fgetcsv($handle, null, ',', '"', '');

        while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
            if (count($row) !== 3) {
                continue;
            }

            yield [(string) $row[0], (string) $row[1], (int) $row[2]];
        }

        fclose($handle);
    }
}
