<?php

namespace App\Support\Health;

use Illuminate\Support\Facades\Redis;

final class RedisCheck implements HealthCheck
{
    public function name(): string
    {
        return 'redis';
    }

    public function critical(): bool
    {
        return true;
    }

    public function run(): CheckResult
    {
        return CheckResult::measure(function (): array {
            Redis::connection()->ping();

            return [];
        });
    }
}
