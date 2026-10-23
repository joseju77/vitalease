<?php

namespace App\Support\Health;

use Illuminate\Support\Facades\Cache;

/**
 * Scheduled every minute: proves the scheduler process is alive by
 * refreshing a short-lived cache entry read by {@see SchedulerCheck}.
 */
final class SchedulerHeartbeat
{
    public const string CACHE_KEY = 'health:scheduler-heartbeat';

    public const int TTL_SECONDS = 600;

    public function __invoke(): void
    {
        Cache::put(self::CACHE_KEY, now()->getTimestamp(), self::TTL_SECONDS);
    }

    public static function lastBeatAt(): ?int
    {
        $timestamp = Cache::get(self::CACHE_KEY);

        return is_int($timestamp) ? $timestamp : null;
    }
}
