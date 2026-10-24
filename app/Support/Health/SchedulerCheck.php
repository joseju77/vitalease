<?php

namespace App\Support\Health;

use RuntimeException;

final class SchedulerCheck implements HealthCheck
{
    private const int MAX_HEARTBEAT_AGE_SECONDS = 180;

    public function name(): string
    {
        return 'scheduler';
    }

    public function critical(): bool
    {
        return false;
    }

    public function run(): CheckResult
    {
        return CheckResult::measure(function (): array {
            $lastBeatAt = SchedulerHeartbeat::lastBeatAt();

            if ($lastBeatAt === null || now()->getTimestamp() - $lastBeatAt > self::MAX_HEARTBEAT_AGE_SECONDS) {
                throw new RuntimeException('Scheduler heartbeat is missing or stale.');
            }

            return ['heartbeat_age_seconds' => now()->getTimestamp() - $lastBeatAt];
        });
    }
}
