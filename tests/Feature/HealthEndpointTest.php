<?php

use App\Support\Health\CheckResult;
use App\Support\Health\DatabaseCheck;
use App\Support\Health\HealthCheck;
use App\Support\Health\MeilisearchCheck;
use App\Support\Health\QueueCheck;
use App\Support\Health\RedisCheck;
use App\Support\Health\SchedulerCheck;
use App\Support\Health\SchedulerHeartbeat;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function fakeHealthCheck(string $name, bool $critical, bool $up): HealthCheck
{
    return new class($name, $critical, $up) implements HealthCheck
    {
        public function __construct(private string $name, private bool $critical, private bool $up) {}

        public function name(): string
        {
            return $this->name;
        }

        public function critical(): bool
        {
            return $this->critical;
        }

        public function run(): CheckResult
        {
            if (! $this->up) {
                throw new RuntimeException('secret detail');
            }

            return new CheckResult(true, 1.0);
        }
    };
}

function bindHealthChecks(bool $database, bool $redis, bool $meilisearch): void
{
    app()->bind(DatabaseCheck::class, fn () => fakeHealthCheck('database', true, $database));
    app()->bind(RedisCheck::class, fn () => fakeHealthCheck('redis', true, $redis));
    app()->bind(MeilisearchCheck::class, fn () => fakeHealthCheck('meilisearch', false, $meilisearch));
    app()->bind(QueueCheck::class, fn () => fakeHealthCheck('queue', false, true));
    app()->bind(SchedulerCheck::class, fn () => fakeHealthCheck('scheduler', false, true));
}

it('reports ok with 200 and sets no session cookie', function () {
    bindHealthChecks(true, true, true);

    $this->getJson('/health')->assertOk()->assertJsonPath('status', 'ok')->assertJsonPath('checks.database.status', 'up')
        ->assertCookieMissing(config('session.cookie'));
});

it('reports degraded with 200 when a non-critical check is down', function () {
    bindHealthChecks(true, true, false);

    $this->getJson('/health')->assertOk()->assertJsonPath('status', 'degraded')
        ->assertJsonPath('checks.meilisearch.status', 'down')
        ->assertJsonPath('checks.meilisearch.exception', RuntimeException::class);
});

it('answers 503 when a critical check is down', function (bool $database, bool $redis) {
    bindHealthChecks($database, $redis, true);

    $this->getJson('/health')->assertStatus(503)->assertJsonPath('status', 'down');
})->with([[false, true], [true, false]]);

it('keeps /up as liveness without dependency checks', function () {
    bindHealthChecks(true, false, true);

    $this->get('/up')->assertOk();
});

it('reports every component as up with 200 when all checks pass', function () {
    bindHealthChecks(true, true, true);

    $this->getJson('/health')->assertOk()
        ->assertJsonPath('checks.database.status', 'up')
        ->assertJsonPath('checks.redis.status', 'up')
        ->assertJsonPath('checks.meilisearch.status', 'up')
        ->assertJsonPath('checks.queue.status', 'up')
        ->assertJsonPath('checks.scheduler.status', 'up');
});

it('names the failing critical component and never exposes the failure message', function () {
    bindHealthChecks(false, true, true);

    $response = $this->getJson('/health')->assertStatus(503)
        ->assertJsonPath('checks.database.status', 'down')
        ->assertJsonPath('checks.redis.status', 'up');

    expect($response->getContent())->not->toContain('secret detail');
});

it('reports degraded with 200 when the queue check is down', function () {
    app()->bind(QueueCheck::class, fn () => fakeHealthCheck('queue', false, false));
    app()->bind(DatabaseCheck::class, fn () => fakeHealthCheck('database', true, true));
    app()->bind(RedisCheck::class, fn () => fakeHealthCheck('redis', true, true));
    app()->bind(MeilisearchCheck::class, fn () => fakeHealthCheck('meilisearch', false, true));

    $this->getJson('/health')->assertOk()->assertJsonPath('status', 'degraded')->assertJsonPath('checks.queue.status', 'down');
});

it('still answers with component statuses when the session store is unreachable', function () {
    config(['session.driver' => 'redis', 'database.redis.default.host' => 'unreachable.invalid']);
    bindHealthChecks(true, true, true);

    $this->getJson('/health')->assertOk()->assertJsonPath('status', 'ok');
});

it('keeps /up as liveness while /health reports a critical outage', function () {
    bindHealthChecks(true, false, true);

    $this->get('/up')->assertOk();
    $this->getJson('/health')->assertStatus(503)->assertJsonPath('checks.redis.status', 'down');
});

it('reports queue depth and failed job count', function () {
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'redis',
        'queue' => 'default',
        'payload' => '{}',
        'exception' => 'x',
    ]);

    $result = app(QueueCheck::class)->run();

    expect($result->up)->toBeTrue()
        ->and($result->details)->toBe(['pending' => 0, 'failed' => 1]);
});

it('marks the scheduler up after a heartbeat and down once the heartbeat is stale or missing', function () {
    expect(fn () => app(SchedulerCheck::class)->run())->toThrow(RuntimeException::class);

    (new SchedulerHeartbeat)();
    expect(app(SchedulerCheck::class)->run()->up)->toBeTrue();

    $this->travel(181)->seconds();
    expect(fn () => app(SchedulerCheck::class)->run())->toThrow(RuntimeException::class);
});

it('schedules failed-job pruning and the scheduler heartbeat', function () {
    $commands = collect(app(Schedule::class)->events())->map(fn ($event) => $event->command.'|'.$event->description)->implode(' ');

    expect($commands)->toContain('queue:prune-failed --hours=168')
        ->and($commands)->toContain('scheduler-heartbeat');
});
