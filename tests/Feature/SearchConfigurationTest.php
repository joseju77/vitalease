<?php

use App\Models\Medication;
use App\Models\Patient;
use GuzzleHttp\ClientInterface;
use Meilisearch\Client as MeilisearchClient;

/**
 * Read the Guzzle option the Meilisearch client was built with.
 */
function meilisearchHttpOption(string $option): mixed
{
    $http = (new ReflectionProperty(MeilisearchClient::class, 'http'))->getValue(app(MeilisearchClient::class));
    $guzzle = (new ReflectionProperty($http, 'http'))->getValue($http);

    expect($guzzle)->toBeInstanceOf(ClientInterface::class);

    return $guzzle->getConfig($option);
}

it('builds the Meilisearch client with finite default timeouts', function () {
    expect(meilisearchHttpOption('connect_timeout'))->toBe(1.0)
        ->and(meilisearchHttpOption('timeout'))->toBe(3.0);
});

it('applies configured Meilisearch timeouts to the client', function () {
    config(['scout.meilisearch.connect_timeout' => 2.5, 'scout.meilisearch.timeout' => 30.0]);
    app()->forgetInstance(MeilisearchClient::class);

    expect(meilisearchHttpOption('connect_timeout'))->toBe(2.5)
        ->and(meilisearchHttpOption('timeout'))->toBe(30.0);
});

it('declares last name and first name as sortable for patients and name for medications', function () {
    $settings = config('scout.meilisearch.index-settings');

    expect($settings[Patient::class]['sortableAttributes'])->toBe(['last_name', 'first_name'])
        ->and($settings[Medication::class]['sortableAttributes'])->toContain('name');
});

it('configures finite PostgreSQL and Redis connection timeouts', function () {
    expect(config('database.connections.pgsql.options')[PDO::ATTR_TIMEOUT])->toBeInt()->toBeGreaterThan(0)
        ->and(config('database.redis.default.timeout'))->toBeGreaterThan(0)
        ->and(config('database.redis.default.read_timeout'))->toBeGreaterThan(0)
        ->and(config('database.redis.cache.timeout'))->toBeGreaterThan(0)
        ->and(config('database.redis.cache.read_timeout'))->toBeGreaterThan(0);
});

it('stores failed jobs in the database', function () {
    expect(config('queue.failed.driver'))->toBe('database-uuids')
        ->and(config('queue.failed.table'))->toBe('failed_jobs');
});
