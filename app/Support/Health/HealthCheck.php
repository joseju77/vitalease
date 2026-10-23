<?php

namespace App\Support\Health;

/**
 * One dependency probe reported by the `/health` endpoint. Implementations
 * throw when the dependency is unreachable; the caller records the failure.
 */
interface HealthCheck
{
    /**
     * Stable component name used as the key in the health report.
     */
    public function name(): string;

    /**
     * Whether the application cannot serve requests without this component.
     */
    public function critical(): bool;

    public function run(): CheckResult;
}
