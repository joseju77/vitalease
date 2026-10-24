<?php

use App\Models\User;
use Illuminate\Database\LostConnectionException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;

function queryExceptionWithSqlState(string $state): QueryException
{
    $pdo = new PDOException('SQLSTATE['.$state.']: failure');
    $pdo->errorInfo = [$state, 7, 'failure'];

    return new QueryException('pgsql', 'select 1', [], $pdo);
}

it('renders a Spanish 503 Inertia page for connection-class failures', function (string $state) {
    Route::get('/_outage', fn () => throw queryExceptionWithSqlState($state));

    $response = $this->get('/_outage');

    $response->assertStatus(503)->assertHeader('Retry-After', '30');
    expect($response->viewData('page')['component'])->toBe('errors/ServiceUnavailable')
        ->and($response->viewData('page')['props'])->not->toHaveKey('auth');
})->with(['08006', '57P01', '53300']);

it('renders a Redis failure as 503 JSON for JSON requests', function () {
    Route::get('/_outage', fn () => throw new RedisException('connection refused'));

    $this->getJson('/_outage')->assertStatus(503)->assertJson(['message' => 'Servicio temporalmente no disponible.']);
});

it('keeps non-connection database errors as 500', function () {
    Route::get('/_outage', fn () => throw queryExceptionWithSqlState('23505'));

    $this->get('/_outage')->assertStatus(500);
});

it('renders the same 503 page for a lost database connection', function () {
    Route::get('/_outage', fn () => throw new LostConnectionException('server closed the connection unexpectedly'));

    $response = $this->get('/_outage');

    $response->assertStatus(503);
    expect($response->viewData('page')['component'])->toBe('errors/ServiceUnavailable');
});

it('renders the same 503 page for a Redis connection failure', function () {
    Route::get('/_outage', fn () => throw new RedisException('connection refused'));

    $response = $this->get('/_outage');

    $response->assertStatus(503)->assertHeader('Retry-After', '30');
    expect($response->viewData('page')['component'])->toBe('errors/ServiceUnavailable');
});

it('answers 503 JSON with a message and no trace for database connection failures', function (string $state) {
    Route::get('/_outage', fn () => throw queryExceptionWithSqlState($state));

    $response = $this->getJson('/_outage')->assertStatus(503)->assertExactJson(['message' => 'Servicio temporalmente no disponible.']);

    expect($response->getContent())->not->toContain('select 1')->not->toContain('SQLSTATE');
})->with(['08006', '57P01', '57P02', '57P03', '53300']);

it('keeps a unique-constraint violation as 500 for JSON requests too', function () {
    Route::get('/_outage', fn () => throw queryExceptionWithSqlState('23505'));

    $this->getJson('/_outage')->assertStatus(500);
});

it('renders the 503 page without shared auth props even for an authenticated user', function () {
    Route::get('/_outage', fn () => throw queryExceptionWithSqlState('08006'));

    $response = $this->actingAs(User::factory()->create())->get('/_outage');

    $response->assertStatus(503);
    expect($response->viewData('page')['props'])->not->toHaveKey('auth')
        ->and($response->getContent())->not->toContain('SQLSTATE');
});
