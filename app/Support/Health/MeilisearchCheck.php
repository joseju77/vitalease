<?php

namespace App\Support\Health;

use Meilisearch\Client;
use RuntimeException;

final class MeilisearchCheck implements HealthCheck
{
    public function __construct(private readonly Client $client) {}

    public function name(): string
    {
        return 'meilisearch';
    }

    public function critical(): bool
    {
        return false;
    }

    public function run(): CheckResult
    {
        return CheckResult::measure(function (): array {
            $health = $this->client->health();

            if (($health['status'] ?? null) !== 'available') {
                throw new RuntimeException('Meilisearch reported an unavailable status.');
            }

            return [];
        });
    }
}
