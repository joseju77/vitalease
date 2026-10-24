<?php

namespace App\Support\Search;

use Meilisearch\Exceptions\ApiException;
use Meilisearch\Exceptions\CommunicationException;
use Meilisearch\Exceptions\InvalidResponseBodyException;
use Meilisearch\Exceptions\TimeOutException;
use Throwable;

/**
 * Decides whether a search engine failure means "engine unavailable"
 * (connection failure, timeout, non-JSON body, HTTP 5xx, missing index) and
 * so justifies the database fallback. Engine 4xx errors, such as an invalid
 * sort, are caller bugs and must surface.
 */
final class SearchFailure
{
    public static function isEngineUnavailable(Throwable $exception): bool
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if (self::matches($current)) {
                return true;
            }
        }

        return false;
    }

    private static function matches(Throwable $exception): bool
    {
        if ($exception instanceof CommunicationException
            || $exception instanceof TimeOutException
            || $exception instanceof InvalidResponseBodyException) {
            return true;
        }

        return $exception instanceof ApiException
            && ($exception->httpStatus >= 500 || $exception->errorCode === 'index_not_found');
    }
}
