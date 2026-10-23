<?php

use App\Enums\Permission;
use App\Models\Medication;
use App\Models\Patient;
use App\Models\User;
use App\Support\Search\TextTokens;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Support\Facades\Log;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\CollectionEngine;
use Meilisearch\Exceptions\ApiException;
use Meilisearch\Exceptions\CommunicationException;
use Meilisearch\Exceptions\InvalidResponseBodyException;
use Tests\Support\PatientFixtures;

function failingEngine(Throwable $exception): void
{
    app(EngineManager::class)->extend('failing', fn () => new class($exception) extends CollectionEngine
    {
        public function __construct(private Throwable $exception)
        {
            parent::__construct();
        }

        public function paginate($builder, $perPage, $page)
        {
            throw $this->exception;
        }

        public function search($builder)
        {
            throw $this->exception;
        }
    });
    config(['scout.driver' => 'failing']);
}

function apiException(int $status, string $code): ApiException
{
    return new ApiException(new PsrResponse($status), ['message' => 'x', 'code' => $code]);
}

it('falls back to the database for engine outages and flags the page', function (Closure $exception) {
    PatientFixtures::register(['patient' => ['first_name' => 'Ana', 'last_name' => 'Lopez', 'second_last_name' => null]]);
    failingEngine($exception());

    $response = $this->actingAs(PatientFixtures::actor('view'))->get(route('patients.index', ['q' => 'ana lopez']));

    $response->assertOk();
    expect($response->viewData('page')['props']['patients']['data'])->toHaveCount(1)
        ->and($response->viewData('page')['props']['search_degraded'])->toBeTrue();
})->with([
    'communication' => [fn () => new CommunicationException('down')],
    'api 503' => [fn () => apiException(503, 'internal')],
    'invalid body' => [fn () => new InvalidResponseBodyException(new PsrResponse(200), 'not json')],
    'index not found' => [fn () => apiException(404, 'index_not_found')],
]);

it('does not mask engine 4xx errors', function () {
    failingEngine(apiException(400, 'invalid_search_sort'));

    $this->withoutExceptionHandling()->actingAs(PatientFixtures::actor('view'))
        ->get(route('patients.index', ['q' => 'ana']));
})->throws(ApiException::class);

it('matches LIKE wildcards literally', function () {
    PatientFixtures::register(['patient' => ['first_name' => 'Ana', 'last_name' => 'Lopez', 'second_last_name' => null]]);

    expect(Patient::query()->matchingText('%')->count())->toBe(0)
        ->and(TextTokens::containsPattern('a_b%'))->toBe('%a\_b\%%');
});

it('flags the dashboard search with a header and keeps the body shape', function () {
    PatientFixtures::register(['patient' => ['first_name' => 'Ana', 'last_name' => 'Lopez', 'second_last_name' => null]]);
    failingEngine(new CommunicationException('down'));

    $this->actingAs(PatientFixtures::actor('view'))
        ->getJson(route('dashboard.patients.search', ['query' => 'ana']))
        ->assertOk()->assertHeader('X-Search-Degraded', '1')->assertJsonCount(1)->assertJsonPath('0.first_name', 'Ana');
});

it('falls back to the database for medications on every engine outage and flags the page', function (Closure $exception) {
    Medication::factory()->create(['name' => 'Paracetamol', 'presentation' => 'Tableta', 'concentration' => '500 mg']);
    Medication::factory()->create(['name' => 'Ibuprofeno']);
    failingEngine($exception());

    $response = $this->actingAs(User::factory()->withPermissions(Permission::InventoryView)->create())
        ->get(route('inventory.index', ['q' => 'paracetamol']));

    $response->assertOk();
    expect(collect($response->viewData('page')['props']['medications']['data'])->pluck('name')->all())->toBe(['Paracetamol'])
        ->and($response->viewData('page')['props']['search_degraded'])->toBeTrue();
})->with([
    'communication' => [fn () => new CommunicationException('down')],
    'api 503' => [fn () => apiException(503, 'internal')],
    'invalid body' => [fn () => new InvalidResponseBodyException(new PsrResponse(200), 'not json')],
    'index not found' => [fn () => apiException(404, 'index_not_found')],
]);

it('does not mask engine 4xx errors on the medications and dashboard searches', function (string $route) {
    failingEngine(apiException(400, 'invalid_search_sort'));

    $this->withoutExceptionHandling()->actingAs(User::factory()->superAdmin()->create())
        ->getJson(route($route, ['q' => 'ana', 'query' => 'ana']));
})->throws(ApiException::class)->with(['inventory.index', 'dashboard.patients.search']);

it('matches medications by presentation and concentration and keeps the low-stock filter while degraded', function () {
    Medication::factory()->create(['name' => 'Amoxicilina', 'presentation' => 'Capsula', 'concentration' => '875 mg', 'current_stock' => 1, 'minimum_stock' => 5]);
    Medication::factory()->create(['name' => 'Amoxidal', 'presentation' => 'Capsula', 'concentration' => '875 mg', 'current_stock' => 50, 'minimum_stock' => 5]);
    failingEngine(new CommunicationException('down'));
    $viewer = User::factory()->withPermissions(Permission::InventoryView)->create();

    $all = $this->actingAs($viewer)->get(route('inventory.index', ['q' => 'capsula 875']));
    $lowStock = $this->actingAs($viewer)->get(route('inventory.index', ['q' => 'capsula 875', 'low_stock' => 1]));

    expect(collect($all->viewData('page')['props']['medications']['data'])->pluck('name')->sort()->values()->all())->toBe(['Amoxicilina', 'Amoxidal'])
        ->and(collect($lowStock->viewData('page')['props']['medications']['data'])->pluck('name')->all())->toBe(['Amoxicilina'])
        ->and($lowStock->viewData('page')['props']['filters']['low_stock'])->toBeTrue();
});

it('keeps the disabled filter, last name ordering and the query in pagination links while degraded', function () {
    foreach (range(1, 16) as $number) {
        PatientFixtures::register(['patient' => ['first_name' => 'Ana', 'last_name' => sprintf('Zeta%02d', $number), 'second_last_name' => null]]);
    }
    $disabled = PatientFixtures::registerDisabled();
    $disabled->forceFill(['first_name' => 'Ana', 'last_name' => 'Alfa', 'second_last_name' => null])->saveQuietly();
    failingEngine(new CommunicationException('down'));
    $viewer = PatientFixtures::actor('view');

    $active = $this->actingAs($viewer)->get(route('patients.index', ['q' => 'ana']))->viewData('page')['props']['patients'];
    $onlyDisabled = $this->actingAs($viewer)->get(route('patients.index', ['q' => 'ana', 'status' => 'disabled']))->viewData('page')['props']['patients'];

    expect($active['total'])->toBe(16)
        ->and($active['data'])->toHaveCount(15)
        ->and($active['data'][0]['full_name'])->toStartWith('Ana Zeta01')
        ->and($active['next_page_url'])->toContain('q=ana')
        ->and($onlyDisabled['total'])->toBe(1)
        ->and($onlyDisabled['data'][0]['full_name'])->toStartWith('Ana Alfa');
});

it('requires every token to match across different fields and rejects a patient matching one token only', function () {
    PatientFixtures::register(['patient' => ['first_name' => 'Ana', 'last_name' => 'Lopez', 'second_last_name' => null]]);
    PatientFixtures::register(['patient' => ['first_name' => 'Ana', 'last_name' => 'Soto', 'second_last_name' => null]]);
    PatientFixtures::register(['patient' => ['first_name' => 'Luis', 'last_name' => 'Lopez', 'second_last_name' => null]]);

    $names = Patient::query()->matchingText('ana lopez')->get()->map(fn (Patient $patient) => $patient->first_name.' '.$patient->last_name)->all();

    expect($names)->toBe(['Ana Lopez']);
});

it('matches case-insensitively and by enrollment number', function () {
    Patient::factory()->withEnrollment()->create(['first_name' => 'Ana', 'last_name' => 'Lopez', 'second_last_name' => null, 'enrollment_number' => '424242424']);

    expect(Patient::query()->matchingText('LOPEZ')->count())->toBe(1)
        ->and(Patient::query()->matchingText('424242')->count())->toBe(1);
});

it('does not widen results when the query contains underscore or percent wildcards', function () {
    PatientFixtures::register(['patient' => ['first_name' => 'Ana', 'last_name' => 'Lopez', 'second_last_name' => null]]);

    expect(Patient::query()->matchingText('A_a')->count())->toBe(0)
        ->and(Patient::query()->matchingText('L%z')->count())->toBe(0)
        ->and(Patient::query()->matchingText('Lop')->count())->toBe(1);
});

it('caps the fallback at five tokens', function () {
    expect(TextTokens::from('a b c d e f g'))->toBe(['a', 'b', 'c', 'd', 'e'])
        ->and(TextTokens::from("  ana \t lopez  "))->toBe(['ana', 'lopez']);
});

it('includes disabled patients and returns at most ten on the degraded dashboard search', function () {
    foreach (range(1, 11) as $number) {
        PatientFixtures::register(['patient' => ['first_name' => 'Ana', 'last_name' => sprintf('Perez%02d', $number), 'second_last_name' => null]]);
    }
    $disabled = PatientFixtures::registerDisabled();
    $disabled->forceFill(['first_name' => 'Ana', 'last_name' => 'Abad', 'second_last_name' => null])->saveQuietly();
    failingEngine(new CommunicationException('down'));

    $body = $this->actingAs(PatientFixtures::actor('view'))
        ->getJson(route('dashboard.patients.search', ['query' => 'ana']))
        ->assertOk()->assertHeader('X-Search-Degraded', '1')->json();

    expect($body)->toHaveCount(10)
        ->and($body[0]['last_name'])->toBe('Abad')
        ->and($body[0]['is_disabled'])->toBeTrue();
});

it('does not flag a healthy search on any of the three endpoints', function () {
    PatientFixtures::register(['patient' => ['first_name' => 'Ana', 'last_name' => 'Lopez', 'second_last_name' => null]]);
    Medication::factory()->create(['name' => 'Paracetamol']);
    $admin = User::factory()->superAdmin()->create();

    $patients = $this->actingAs($admin)->get(route('patients.index', ['q' => 'ana']));
    $medications = $this->actingAs($admin)->get(route('inventory.index', ['q' => 'para']));
    $dashboard = $this->actingAs($admin)->getJson(route('dashboard.patients.search', ['query' => 'ana']));

    expect($patients->viewData('page')['props']['search_degraded'])->toBeFalse()
        ->and($medications->viewData('page')['props']['search_degraded'])->toBeFalse();
    $dashboard->assertOk()->assertHeaderMissing('X-Search-Degraded');
});

it('logs a warning with the index and exception class but never the query text', function () {
    Log::spy();
    failingEngine(new CommunicationException('down'));

    $this->actingAs(PatientFixtures::actor('view'))->get(route('patients.index', ['q' => 'secretname']))->assertOk();

    Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context = []): bool {
        return str_contains(json_encode($context), 'patients')
            && str_contains(json_encode($context), 'CommunicationException')
            && ! str_contains($message.json_encode($context), 'secretname');
    });
});
