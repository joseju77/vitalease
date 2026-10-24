<?php

namespace App\Support\Search;

use Closure;
use Throwable;

/**
 * Runs a search against the engine and, only when the engine is
 * unavailable, against the database fallback instead.
 */
final readonly class ResilientSearch
{
    public function __construct(private SearchDegradation $degradation) {}

    /**
     * @template T
     *
     * @param  Closure(): T  $engine
     * @param  Closure(): T  $fallback
     * @return T
     */
    public function run(string $index, Closure $engine, Closure $fallback): mixed
    {
        try {
            return $engine();
        } catch (Throwable $exception) {
            if (! SearchFailure::isEngineUnavailable($exception)) {
                throw $exception;
            }

            $this->degradation->record($index, $exception);

            return $fallback();
        }
    }
}
