<?php

namespace App\Support\Search;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Request-scoped record of whether a search fell back to the database.
 * Bound with `scoped()` so each request (and queue job) starts clean.
 */
final class SearchDegradation
{
    private bool $degraded = false;

    /**
     * Mark the request as degraded and log a warning. The log carries the
     * index and the exception class only: the query text may hold patient
     * data and is never written.
     */
    public function record(string $index, Throwable $exception): void
    {
        $this->degraded = true;

        Log::warning('Search engine unavailable, falling back to the database.', [
            'index' => $index,
            'exception' => $exception::class,
        ]);
    }

    public function isDegraded(): bool
    {
        return $this->degraded;
    }
}
