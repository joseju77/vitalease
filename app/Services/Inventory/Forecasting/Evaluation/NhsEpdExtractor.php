<?php

namespace App\Services\Inventory\Forecasting\Evaluation;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

/**
 * Builds a local, resumable extract of the NHS English Prescribing Dataset for
 * a deterministic sample of GP practices.
 *
 * Layout under the extract directory:
 *   sample.json        sampling parameters and the selected practice codes
 *   months/YYYYMM.csv  practice_code,bnf_chemical_substance,items (sorted)
 *   manifest.json      endpoint, SQL, row counts and sha256 per month
 *
 * The extract is raw NHS data and must stay git-ignored.
 */
final class NhsEpdExtractor
{
    public const UNIT = 'ITEMS';

    public const CHUNK_SIZE = 10;

    private const CSV_HEADER = ['practice_code', 'bnf_chemical_substance', 'items'];

    public function __construct(private readonly NhsEpdClient $client) {}

    /**
     * @param  callable(string): void|null  $progress
     * @return array{months: int, requested: int, skipped: int, practices: int, extract_sha256: string}
     */
    public function extract(
        string $directory,
        string $from,
        string $to,
        int $practices,
        int $seed,
        int $minPracticeItems,
        ?callable $progress = null,
    ): array {
        $months = self::monthRange($from, $to);

        if ($practices < 1 || $minPracticeItems < 0) {
            throw new InvalidArgumentException('The practice count must be positive and the minimum items non-negative.');
        }

        $parameters = [
            'from' => $from,
            'to' => $to,
            'practices' => $practices,
            'seed' => $seed,
            'min_practice_items' => $minPracticeItems,
            'unit' => self::UNIT,
        ];

        $this->ensureDirectory($directory.'/months');

        $sample = $this->loadOrCreateSample($directory, $parameters, $months, $progress);
        $codes = $sample['practices'];

        $manifest = $this->readJson($directory.'/manifest.json') ?? [];
        $manifest['endpoint'] = NhsEpdClient::ENDPOINT;
        $manifest['unit'] = self::UNIT;
        $manifest['parameters'] = $parameters;
        $manifest['months'] = is_array($manifest['months'] ?? null) ? $manifest['months'] : [];

        $requested = 0;
        $skipped = 0;

        foreach ($months as $month) {
            $file = "{$directory}/months/{$month}.csv";
            $recorded = $manifest['months'][$month]['sha256'] ?? null;

            if ($recorded !== null && is_file($file) && hash_file('sha256', $file) === $recorded) {
                $skipped++;

                continue;
            }

            $resourceId = NhsEpdClient::resourceIdForMonth($month);
            $rows = [];
            $queries = [];

            foreach (array_chunk($codes, self::CHUNK_SIZE) as $chunk) {
                $queries[] = $this->client->monthSql($resourceId, $chunk);
                $requested++;

                foreach ($this->client->monthItems($resourceId, $chunk) as $row) {
                    if (! in_array($row['practice_code'], $chunk, true)) {
                        throw new RuntimeException('The NHS API returned a practice outside the requested sample.');
                    }

                    $rows[] = $row;
                }
            }

            usort($rows, static fn (array $a, array $b): int => [$a['practice_code'], $a['bnf_chemical_substance']] <=> [$b['practice_code'], $b['bnf_chemical_substance']]);

            $this->writeCsv($file, $rows);

            $manifest['months'][$month] = [
                'resource_id' => $resourceId,
                'sql' => $queries,
                'rows' => count($rows),
                'sha256' => hash_file('sha256', $file),
            ];
            ksort($manifest['months']);
            $this->writeJson($directory.'/manifest.json', $manifest);

            if ($progress !== null) {
                $progress("Extracted {$month} (".count($rows).' rows)');
            }
        }

        $hashes = [];
        foreach ($months as $month) {
            $hashes[] = $month.':'.$manifest['months'][$month]['sha256'];
        }

        $manifest['extract_sha256'] = hash('sha256', implode("\n", $hashes)."\n");
        $this->writeJson($directory.'/manifest.json', $manifest);

        return [
            'months' => count($months),
            'requested' => $requested,
            'skipped' => $skipped,
            'practices' => count($codes),
            'extract_sha256' => $manifest['extract_sha256'],
        ];
    }

    /**
     * @return list<string> Months as YYYYMM, ascending.
     */
    public static function monthRange(string $from, string $to): array
    {
        $start = self::parseMonth($from);
        $end = self::parseMonth($to);

        if ($start > $end) {
            throw new InvalidArgumentException('The --from month must not be after the --to month.');
        }

        $months = [];
        for ($cursor = $start; $cursor <= $end; $cursor = $cursor->modify('+1 month')) {
            $months[] = $cursor->format('Ym');
        }

        return $months;
    }

    private static function parseMonth(string $value): DateTimeImmutable
    {
        $date = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) === 1
            ? DateTimeImmutable::createFromFormat('!Y-m', $value)
            : false;

        if ($date === false) {
            throw new InvalidArgumentException("Invalid month '{$value}'; expected YYYY-MM.");
        }

        return $date;
    }

    /**
     * @param  array<string, int|string>  $parameters
     * @param  list<string>  $months
     * @param  callable(string): void|null  $progress
     * @return array{parameters: array<string, int|string>, practices: list<string>}
     */
    private function loadOrCreateSample(string $directory, array $parameters, array $months, ?callable $progress): array
    {
        $path = $directory.'/sample.json';
        $existing = $this->readJson($path);

        if ($existing !== null) {
            if (($existing['parameters'] ?? null) !== $parameters || ! is_array($existing['practices'] ?? null)) {
                throw new RuntimeException("An extract with different parameters already exists in {$directory}; use another --path or remove it.");
            }

            return $existing;
        }

        $first = $this->client->practiceTotals(NhsEpdClient::resourceIdForMonth($months[0]));
        $last = $this->client->practiceTotals(NhsEpdClient::resourceIdForMonth($months[array_key_last($months)]));

        $eligible = [];
        $invalid = 0;

        foreach ($first as $code => $items) {
            $code = (string) $code;

            if (($last[$code] ?? 0) < $parameters['min_practice_items'] || $items < $parameters['min_practice_items']) {
                continue;
            }

            if (! NhsEpdClient::isValidPracticeCode($code)) {
                $invalid++;

                continue;
            }

            $eligible[] = $code;
        }

        sort($eligible, SORT_STRING);

        if (count($eligible) < $parameters['practices']) {
            throw new RuntimeException('Only '.count($eligible)." practices are eligible but {$parameters['practices']} were requested.");
        }

        $shuffled = (new Randomizer(new Mt19937((int) $parameters['seed'])))->shuffleArray($eligible);
        $selected = array_slice($shuffled, 0, (int) $parameters['practices']);
        sort($selected, SORT_STRING);

        $sample = [
            'parameters' => $parameters,
            'eligible_practices' => count($eligible),
            'excluded_invalid_code' => $invalid,
            'practices' => $selected,
        ];

        $this->writeJson($path, $sample);

        if ($progress !== null) {
            $progress('Sampled '.count($selected).' of '.count($eligible).' eligible practices');
        }

        return $sample;
    }

    /**
     * @param  list<array{practice_code: string, bnf_chemical_substance: string, items: int}>  $rows
     */
    private function writeCsv(string $path, array $rows): void
    {
        $temporary = $path.'.tmp';
        $handle = fopen($temporary, 'wb');

        if ($handle === false) {
            throw new RuntimeException("Cannot write {$temporary}.");
        }

        fputcsv($handle, self::CSV_HEADER, ',', '"', '');

        foreach ($rows as $row) {
            fputcsv($handle, [$row['practice_code'], $row['bnf_chemical_substance'], $row['items']], ',', '"', '');
        }

        fclose($handle);
        rename($temporary, $path);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function writeJson(string $path, array $data): void
    {
        $temporary = $path.'.tmp';

        file_put_contents($temporary, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
        rename($temporary, $path);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJson(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        try {
            $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException("The file {$path} is not valid JSON; remove it to start again.");
        }

        return is_array($data) ? $data : null;
    }

    private function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException("Cannot create {$directory}.");
        }
    }
}
