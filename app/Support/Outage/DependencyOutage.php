<?php

namespace App\Support\Outage;

use Illuminate\Database\LostConnectionDetector;
use Illuminate\Database\LostConnectionException;
use Illuminate\Database\QueryException;
use PDOException;
use RedisException;
use Throwable;

/**
 * Classifies exceptions that mean a critical dependency (PostgreSQL or Redis)
 * is unreachable, as opposed to an application or data error.
 */
final class DependencyOutage
{
    /**
     * SQLSTATE class 08 (connection exception) plus admin shutdown, crash
     * shutdown, cannot-connect-now and too-many-connections.
     */
    private const string SQLSTATE_PATTERN = '/^(08|57P01$|57P02$|57P03$|53300$)/';

    public static function causedBy(Throwable $exception): bool
    {
        $detector = new LostConnectionDetector;

        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof RedisException || $current instanceof LostConnectionException) {
                return true;
            }

            // A QueryException message embeds the SQL and bindings; its
            // wrapped PDOException (visited next) carries the driver message.
            if ($current instanceof PDOException
                && (self::matchesSqlState($current)
                    || (! $current instanceof QueryException && $detector->causedByLostConnection($current)))) {
                return true;
            }
        }

        return false;
    }

    private static function matchesSqlState(PDOException $exception): bool
    {
        $candidates = [$exception->getCode()];

        if (is_array($exception->errorInfo)) {
            $candidates[] = $exception->errorInfo[0] ?? null;
        }

        if (preg_match('/SQLSTATE\[([0-9A-Z]{5})\]/', $exception->getMessage(), $matches) === 1) {
            $candidates[] = $matches[1];
        }

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && preg_match(self::SQLSTATE_PATTERN, $candidate) === 1) {
                return true;
            }
        }

        return false;
    }
}
