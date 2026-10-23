<?php

namespace App\Support\Health;

/**
 * Outcome of one {@see HealthCheck}.
 */
final readonly class CheckResult
{
    /**
     * @param  array<string, scalar|null>  $details
     */
    public function __construct(
        public bool $up,
        public float $latencyMs,
        public array $details = [],
    ) {}

    /**
     * Run a probe and capture its latency in milliseconds.
     *
     * @param  callable(): array<string, scalar|null>  $probe
     */
    public static function measure(callable $probe): self
    {
        $start = hrtime(true);
        $details = $probe();

        return new self(true, round((hrtime(true) - $start) / 1_000_000, 2), $details);
    }
}
