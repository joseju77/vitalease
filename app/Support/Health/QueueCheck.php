<?php

namespace App\Support\Health;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Reports queue depth and stored failed jobs. Non-critical: requests are
 * served without workers, indexing only catches up later.
 */
final class QueueCheck implements HealthCheck
{
    public function name(): string
    {
        return 'queue';
    }

    public function critical(): bool
    {
        return false;
    }

    public function run(): CheckResult
    {
        return CheckResult::measure(fn (): array => [
            'pending' => Queue::size(),
            'failed' => DB::table('failed_jobs')->count(),
        ]);
    }
}
