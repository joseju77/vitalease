<?php

namespace App\Services\Inventory\Forecasting\Evaluation;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Thin client for the NHSBSA Open Data Portal `datastore_search_sql` endpoint
 * (English Prescribing Dataset, SNOMED edition). The backend is BigQuery, so
 * aggregate filters (HAVING) are not available and callers filter in PHP.
 *
 * The endpoint requires the monthly resource id both as a `resource_id`
 * parameter and as a backtick-quoted table in the SQL.
 *
 * Practice codes and resource ids come from the API or from options and are
 * validated against strict patterns before being interpolated into SQL.
 */
final class NhsEpdClient
{
    public const ENDPOINT = 'https://opendata.nhsbsa.net/api/3/action/datastore_search_sql';

    private const PRACTICE_CODE_PATTERN = '/^[A-Z0-9]{3,8}$/';

    private const RESOURCE_PATTERN = '/^EPD_SNOMED_\d{6}$/';

    private const MAX_DELAY_MILLISECONDS = 32000;

    public function __construct(
        private readonly int $maxAttempts = 5,
        private readonly int $baseDelayMilliseconds = 2000,
        private readonly int $timeoutSeconds = 120,
    ) {}

    public static function isValidPracticeCode(string $code): bool
    {
        return preg_match(self::PRACTICE_CODE_PATTERN, $code) === 1;
    }

    public static function resourceIdForMonth(string $yearMonth): string
    {
        return 'EPD_SNOMED_'.$yearMonth;
    }

    /**
     * SQL listing the total items per practice in one monthly resource.
     * The unidentified practice code "-" is never selected.
     */
    public function practiceTotalsSql(string $resourceId): string
    {
        $this->assertResourceId($resourceId);

        return "SELECT PRACTICE_CODE, SUM(ITEMS) AS ITEMS FROM `{$resourceId}` WHERE PRACTICE_CODE <> '-' GROUP BY PRACTICE_CODE";
    }

    /**
     * SQL aggregating items by practice and BNF chemical substance.
     *
     * @param  list<string>  $practiceCodes
     */
    public function monthSql(string $resourceId, array $practiceCodes): string
    {
        $this->assertResourceId($resourceId);

        if ($practiceCodes === []) {
            throw new InvalidArgumentException('At least one practice code is required.');
        }

        foreach ($practiceCodes as $code) {
            if (! self::isValidPracticeCode($code)) {
                throw new InvalidArgumentException('Invalid practice code supplied to the NHS EPD query.');
            }
        }

        $list = implode(', ', array_map(static fn (string $code): string => "'{$code}'", $practiceCodes));

        return "SELECT PRACTICE_CODE, BNF_CHEMICAL_SUBSTANCE, SUM(ITEMS) AS ITEMS FROM `{$resourceId}` WHERE PRACTICE_CODE IN ({$list}) GROUP BY PRACTICE_CODE, BNF_CHEMICAL_SUBSTANCE";
    }

    /**
     * @return array<string, int> Total items keyed by practice code.
     */
    public function practiceTotals(string $resourceId): array
    {
        $totals = [];

        foreach ($this->query($this->practiceTotalsSql($resourceId), $resourceId) as $record) {
            $code = (string) ($record['PRACTICE_CODE'] ?? '');

            if ($code === '' || $code === '-') {
                continue;
            }

            $totals[$code] = (int) round((float) ($record['ITEMS'] ?? 0));
        }

        return $totals;
    }

    /**
     * @param  list<string>  $practiceCodes
     * @return list<array{practice_code: string, bnf_chemical_substance: string, items: int}>
     */
    public function monthItems(string $resourceId, array $practiceCodes): array
    {
        $rows = [];

        foreach ($this->query($this->monthSql($resourceId, $practiceCodes), $resourceId) as $record) {
            $code = (string) ($record['PRACTICE_CODE'] ?? '');

            if (! self::isValidPracticeCode($code)) {
                throw new RuntimeException('The NHS API returned a row with an invalid practice code.');
            }

            $rows[] = [
                'practice_code' => $code,
                'bnf_chemical_substance' => trim((string) ($record['BNF_CHEMICAL_SUBSTANCE'] ?? '')),
                'items' => (int) round((float) ($record['ITEMS'] ?? 0)),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function query(string $sql, string $resourceId): array
    {
        $this->assertResourceId($resourceId);

        try {
            $response = Http::acceptJson()
                ->timeout($this->timeoutSeconds)
                ->retry(
                    $this->maxAttempts,
                    fn (int $attempt): int => min(self::MAX_DELAY_MILLISECONDS, $this->baseDelayMilliseconds * 2 ** ($attempt - 1)),
                    when: static fn (Throwable $exception): bool => self::isRetryable($exception),
                )
                ->get(self::ENDPOINT, ['resource_id' => $resourceId, 'sql' => $sql]);
        } catch (ConnectionException|RequestException $exception) {
            throw new RuntimeException("The NHS API request failed (up to {$this->maxAttempts} attempts on connection, 429 and 5xx errors): {$exception->getMessage()}", previous: $exception);
        }

        $payload = $response->json();

        if (! is_array($payload) || ($payload['success'] ?? false) !== true) {
            throw new RuntimeException('The NHS API did not report success.');
        }

        $result = Arr::get($payload, 'result.result', Arr::get($payload, 'result'));

        if (! is_array($result)) {
            throw new RuntimeException('The NHS API response has an unexpected shape.');
        }

        if (($result['records_truncated'] ?? false) === true || ($result['records_truncated'] ?? null) === 'true') {
            throw new RuntimeException('The NHS API truncated the records; reduce the query size.');
        }

        $records = $result['records'] ?? null;

        if (! is_array($records)) {
            throw new RuntimeException('The NHS API response has no records.');
        }

        return array_values(array_filter($records, 'is_array'));
    }

    private static function isRetryable(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if ($exception instanceof RequestException) {
            $status = $exception->response->status();

            return $status === 429 || $status >= 500;
        }

        return false;
    }

    private function assertResourceId(string $resourceId): void
    {
        if (preg_match(self::RESOURCE_PATTERN, $resourceId) !== 1) {
            throw new InvalidArgumentException('Invalid NHS EPD resource id.');
        }
    }
}
