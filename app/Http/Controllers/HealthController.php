<?php

namespace App\Http\Controllers;

use App\Support\Health\CheckResult;
use App\Support\Health\HealthCheck;
use Illuminate\Container\Attributes\Tag;
use Illuminate\Http\JsonResponse;
use Throwable;

class HealthController extends Controller
{
    /**
     * @param  iterable<HealthCheck>  $checks
     */
    public function __construct(#[Tag('health.checks')] private readonly iterable $checks) {}

    /**
     * Report the state of every dependency. Answers 503 only when a critical
     * component is down; a non-critical failure reports `degraded` with 200.
     * A failing check exposes its exception class, never its message.
     */
    public function __invoke(): JsonResponse
    {
        $components = [];
        $criticalDown = false;
        $anyDown = false;

        foreach ($this->checks as $check) {
            try {
                $result = $check->run();
            } catch (Throwable $exception) {
                $result = new CheckResult(false, 0.0, ['exception' => $exception::class]);
            }

            $components[$check->name()] = [
                'status' => $result->up ? 'up' : 'down',
                'critical' => $check->critical(),
                'latency_ms' => $result->latencyMs,
                ...$result->details,
            ];

            if (! $result->up) {
                $anyDown = true;
                $criticalDown = $criticalDown || $check->critical();
            }
        }

        $status = $criticalDown ? 'down' : ($anyDown ? 'degraded' : 'ok');

        return response()->json(
            ['status' => $status, 'checks' => $components],
            $criticalDown ? 503 : 200,
            ['Cache-Control' => 'no-store'],
        );
    }
}
