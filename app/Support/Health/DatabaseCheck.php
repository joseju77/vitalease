<?php

namespace App\Support\Health;

use Illuminate\Support\Facades\DB;

final class DatabaseCheck implements HealthCheck
{
    public function name(): string
    {
        return 'database';
    }

    public function critical(): bool
    {
        return true;
    }

    public function run(): CheckResult
    {
        return CheckResult::measure(function (): array {
            DB::select('select 1');

            return [];
        });
    }
}
