<?php

use App\Models\Patient;
use App\Models\PatientContactInformation;
use App\Models\User;
use App\Services\Patients\PatientDisabling;
use App\Services\Patients\PatientWriter;
use Illuminate\Support\Facades\Event;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\CollectionEngine;
use OwenIt\Auditing\Events\AuditCustom;
use Tests\Support\PatientFixtures;

/**
 * Swap the Scout engine for a spy that records every patient sent to the
 * index as `[id, is_disabled, first_name]`.
 */
function patientIndexSpy(): ArrayObject
{
    $indexed = new ArrayObject;

    app(EngineManager::class)->extend('spy', fn () => new class($indexed) extends CollectionEngine
    {
        public function __construct(private ArrayObject $indexed)
        {
            parent::__construct();
        }

        public function update($models): void
        {
            foreach ($models as $model) {
                $this->indexed->append([$model->getKey(), $model->toSearchableArray()['is_disabled'], $model->first_name]);
            }
        }
    });
    config(['scout.driver' => 'spy']);

    return $indexed;
}

/**
 * @return list<string>
 */
function indexedNames(array $query = []): array
{
    $response = test()->actingAs(PatientFixtures::actor('view'))->get(route('patients.index', $query));

    return collect($response->viewData('page')['props']['patients']['data'])->pluck('full_name')->sort()->values()->all();
}

describe('patient index status filter', function () {
    beforeEach(function () {
        PatientFixtures::register(['patient' => ['first_name' => 'Ana', 'last_name' => 'Soto', 'second_last_name' => null]]);
        $disabled = PatientFixtures::registerDisabled();
        $disabled->forceFill(['first_name' => 'Ana', 'last_name' => 'Perez', 'second_last_name' => null])->saveQuietly();
    });

    it('lists only active patients by default and when status is active, without a query', function (array $query) {
        expect(indexedNames($query))->toBe(['Ana Soto']);
    })->with([
        'default' => [[]],
        'active' => [['status' => 'active']],
    ]);

    it('lists disabled or all patients on request, without a query', function (string $status, array $expected) {
        expect(indexedNames(['status' => $status]))->toBe($expected);
    })->with([
        'disabled' => ['disabled', ['Ana Perez']],
        'all' => ['all', ['Ana Perez', 'Ana Soto']],
    ]);

    it('combines the search text with the status filter through the search engine', function (?string $status, array $expected) {
        expect(indexedNames(['q' => 'Ana', ...($status === null ? [] : ['status' => $status])]))->toBe($expected);
    })->with([
        'default is active' => [null, ['Ana Soto']],
        'active' => ['active', ['Ana Soto']],
        'disabled' => ['disabled', ['Ana Perez']],
        'all' => ['all', ['Ana Perez', 'Ana Soto']],
    ]);

    it('rejects an unknown status', function () {
        $this->actingAs(PatientFixtures::actor('view'))->get(route('patients.index', ['status' => 'banned']))
            ->assertSessionHasErrors('status');
    });

    it('never exposes the social security number, emails or phones in a row', function () {
        $response = $this->actingAs(PatientFixtures::actor('view'))->get(route('patients.index', ['status' => 'all']));
        $rows = $response->viewData('page')['props']['patients']['data'];

        expect($rows)->toHaveCount(2);

        foreach ($rows as $row) {
            expect(array_keys($row))->toBe(['uuid', 'full_name', 'enrollment', 'enrollment_number', 'is_disabled']);
        }

        expect(json_encode($rows))->not->toContain('social_security_number', 'email', 'phone');
    });
});

describe('search index synchronization', function () {
    it('reindexes the patient once after a disable commits, flagged as disabled', function () {
        $patient = PatientFixtures::register();
        $indexed = patientIndexSpy();

        $this->actingAs(PatientFixtures::actor('disable'))->post(route('patients.disable', $patient), ['reason' => 'duplicado']);

        expect($indexed->getArrayCopy())->toBe([[$patient->id, true, $patient->first_name]]);
    });

    it('reindexes the patient once after an enable commits, flagged as active', function () {
        $patient = PatientFixtures::registerDisabled();
        $indexed = patientIndexSpy();

        $this->actingAs(PatientFixtures::actor('disable'))->post(route('patients.enable', $patient), ['reason' => 'error']);

        expect($indexed->getArrayCopy())->toBe([[$patient->id, false, $patient->first_name]]);
    });

    it('reindexes the patient with the new name after an edit', function () {
        $patient = PatientFixtures::register();
        $indexed = patientIndexSpy();
        $edit = PatientFixtures::editPayload($patient);

        $this->actingAs(PatientFixtures::actor('update'))
            ->put(route('patients.update', $patient), [...$edit, 'patient' => [...$edit['patient'], 'first_name' => 'Renombrada']]);

        expect($indexed->getArrayCopy())->toBe([[$patient->id, false, 'Renombrada']]);
    });

    it('reindexes after an edit that only changes a child row', function () {
        $patient = PatientFixtures::register();
        $indexed = patientIndexSpy();
        $edit = PatientFixtures::editPayload($patient);

        $this->actingAs(PatientFixtures::actor('update'))
            ->put(route('patients.update', $patient), [...$edit, 'contact_information' => [...$edit['contact_information'], 'address' => 'Solo cambia el domicilio']]);

        expect($indexed->getArrayCopy())->toHaveCount(1);
    });

    it('never reindexes when a disable rolls back', function () {
        $patient = PatientFixtures::register();
        $indexed = patientIndexSpy();
        Event::listen(AuditCustom::class, fn () => throw new RuntimeException('forced failure'));

        expect(fn () => app(PatientDisabling::class)->disable($patient->fresh(), User::factory()->create(), 'duplicado'))
            ->toThrow(RuntimeException::class);

        expect($indexed->getArrayCopy())->toBe([]);
    });

    it('never reindexes when an edit rolls back', function () {
        $patient = PatientFixtures::register();
        $indexed = patientIndexSpy();
        $edit = PatientFixtures::editPayload($patient);
        Event::listen('eloquent.updating: '.PatientContactInformation::class, fn () => throw new RuntimeException('forced failure'));

        expect(fn () => app(PatientWriter::class)->update($patient, [
            ...$edit,
            'patient' => [...$edit['patient'], 'first_name' => 'Nunca indexada'],
            'contact_information' => [...$edit['contact_information'], 'address' => 'Falla aqui'],
        ]))->toThrow(RuntimeException::class);

        expect($indexed->getArrayCopy())->toBe([]);
    });

    it('rejects an invalid state change without reindexing', function () {
        $patient = PatientFixtures::registerDisabled();
        $indexed = patientIndexSpy();

        $this->actingAs(User::factory()->superAdmin()->create())->post(route('patients.disable', $patient), ['reason' => 'otra vez'])
            ->assertSessionHasErrors('patient');

        expect($indexed->getArrayCopy())->toBe([])
            ->and(Patient::query()->whereKey($patient->id)->exists())->toBeTrue();
    });
});
