<?php

namespace Database\Seeders\Concerns;

use JsonException;

trait LoadsCatalog
{
    /**
     * Decode an approved catalog JSON fixture from `database/seeders/data`.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws JsonException
     */
    protected function loadCatalog(string $filename): array
    {
        $path = database_path('seeders/data/'.$filename);

        return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Idempotently upsert catalog rows in chunks inside a transaction.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  list<string>  $uniqueBy
     * @param  list<string>  $updateColumns
     */
    protected function upsertRows(
        string $modelClass,
        array $rows,
        array $uniqueBy,
        array $updateColumns,
        int $chunkSize = 1000
    ): void {
        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            $modelClass::query()->upsert($chunk, $uniqueBy, $updateColumns);
        }
    }
}
