<?php

use App\Enums\Permission;
use App\Models\MedicalConsultation;
use App\Models\Patient;
use App\Models\PatientContactInformation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\CollectionEngine;

describe('guest access', function () {
    it('redirects a guest to login on patient search', function () {
        $response = $this->get(route('dashboard.patients.search', ['query' => 'ab']));

        $response->assertRedirect(route('auth.login'));
    });

    it('redirects a guest to login on the patient summary', function () {
        $patient = Patient::factory()->create();

        $response = $this->get(route('dashboard.patients.summary', $patient));

        $response->assertRedirect(route('auth.login'));
    });

    it('redirects a guest to login on the latest consultations dashboard', function () {
        $response = $this->get(route('dashboard.consultations.latest'));

        $response->assertRedirect(route('auth.login'));
    });
});

describe('patient search', function () {
    it('requires patients.view', function () {
        $caller = User::factory()->create();

        $response = $this->actingAs($caller)->get(route('dashboard.patients.search', ['query' => 'ab']));

        $response->assertForbidden();
    });

    it('matches a patient on the indexed name fields', function (string $field, string $value) {
        $caller = User::factory()->withPermissions(Permission::PatientsView)->create();
        $patient = Patient::factory()->create([$field => $value]);

        $response = $this->actingAs($caller)->getJson(route('dashboard.patients.search', ['query' => $value]));

        $response->assertOk();
        expect(collect($response->json())->pluck('uuid'))->toContain($patient->uuid);
    })->with([
        'first_name' => ['first_name', 'Xiomara'],
        'last_name' => ['last_name', 'Zambrano'],
        'second_last_name' => ['second_last_name', 'Quintanilla'],
    ]);

    it('matches a patient on the indexed enrollment_number field', function () {
        $caller = User::factory()->withPermissions(Permission::PatientsView)->create();
        $patient = Patient::factory()->withEnrollment()->create(['enrollment_number' => 'ENR778899']);

        $response = $this->actingAs($caller)->getJson(route('dashboard.patients.search', ['query' => 'ENR778899']));

        $response->assertOk();
        expect(collect($response->json())->pluck('uuid'))->toContain($patient->uuid);
    });

    it('does not match unindexed fields such as the social security number, external enrollment, or contact data', function () {
        $caller = User::factory()->withPermissions(Permission::PatientsView)->create();
        $patient = Patient::factory()->create([
            'social_security_number' => '99988877001',
            'external_enrollment' => 'EXT-99988877',
        ]);
        PatientContactInformation::factory()->create([
            'patient_id' => $patient->id,
            'phone_number' => '+5215599988877',
            'personal_email' => 'unindexed-99988877@example.com',
        ]);

        $unindexedTerms = [
            '99988877001',
            'EXT-99988877',
            '+5215599988877',
            'unindexed-99988877@example.com',
        ];

        foreach ($unindexedTerms as $term) {
            $response = $this->actingAs($caller)->getJson(route('dashboard.patients.search', ['query' => $term]));

            $response->assertOk();
            expect($response->json())->toBe([]);
        }
    });

    it('rejects a query shorter than 2 characters', function () {
        $caller = User::factory()->withPermissions(Permission::PatientsView)->create();

        $response = $this->actingAs($caller)->getJson(route('dashboard.patients.search', ['query' => 'a']));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('query');
    });

    it('rejects a missing query', function () {
        $caller = User::factory()->withPermissions(Permission::PatientsView)->create();

        $response = $this->actingAs($caller)->getJson(route('dashboard.patients.search'));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('query');
    });

    it('caps results at 10 even when more than 10 patients match', function () {
        $caller = User::factory()->withPermissions(Permission::PatientsView)->create();
        Patient::factory()->count(12)->create(['last_name' => 'Covarrubias']);

        $response = $this->actingAs($caller)->getJson(route('dashboard.patients.search', ['query' => 'Covarrubias']));

        $response->assertOk();
        expect($response->json())->toHaveCount(10);
    });

    it('indexes a patient only after its transaction commits and never when it rolls back', function () {
        $indexedPatientIds = new ArrayObject;

        app(EngineManager::class)->extend('spy', fn () => new class($indexedPatientIds) extends CollectionEngine
        {
            public function __construct(private ArrayObject $indexedPatientIds)
            {
                parent::__construct();
            }

            public function update($models): void
            {
                foreach ($models as $model) {
                    $this->indexedPatientIds->append($model->getKey());
                }
            }
        });
        config(['scout.driver' => 'spy']);

        try {
            DB::transaction(function () {
                Patient::factory()->create();

                throw new RuntimeException('forced rollback');
            });
        } catch (RuntimeException) {
            // Expected: the rolled-back patient must never reach the index.
        }

        expect($indexedPatientIds->getArrayCopy())->toBe([]);

        $patient = DB::transaction(function () use ($indexedPatientIds): Patient {
            $patient = Patient::factory()->create();

            expect($indexedPatientIds->getArrayCopy())->toBe([]);

            return $patient;
        });

        expect($indexedPatientIds->getArrayCopy())->toBe([$patient->id]);
    });
});

describe('patient summary', function () {
    it('requires patients.view and consultations.view together', function () {
        $patient = Patient::factory()->create();
        $onlyPatients = User::factory()->withPermissions(Permission::PatientsView)->create();
        $onlyConsultations = User::factory()->withPermissions(Permission::ConsultationsView)->create();
        $both = User::factory()->withPermissions(Permission::PatientsView, Permission::ConsultationsView)->create();

        $this->actingAs($onlyPatients)->get(route('dashboard.patients.summary', $patient))->assertForbidden();
        $this->actingAs($onlyConsultations)->get(route('dashboard.patients.summary', $patient))->assertForbidden();
        $this->actingAs($both)->get(route('dashboard.patients.summary', $patient))->assertOk();
    });

    it('returns the patient basic data', function () {
        $caller = User::factory()->withPermissions(Permission::PatientsView, Permission::ConsultationsView)->create();
        $patient = Patient::factory()->create();

        $response = $this->actingAs($caller)->getJson(route('dashboard.patients.summary', $patient));

        $response->assertOk();
        $response->assertJson([
            'patient' => [
                'uuid' => $patient->uuid,
                'first_name' => $patient->first_name,
                'last_name' => $patient->last_name,
                'enrollment_number' => $patient->enrollment_number,
            ],
        ]);
    });

    it('returns exactly the 5 most recent consultations, newest first', function () {
        $caller = User::factory()->withPermissions(Permission::PatientsView, Permission::ConsultationsView)->create();
        $physician = User::factory()->create();
        $patient = Patient::factory()->create();

        $base = now();
        $consultations = collect(range(0, 7))->map(function (int $minute) use ($base, $patient, $physician) {
            $this->travelTo($base->copy()->addMinutes($minute));

            return MedicalConsultation::factory()->withoutRegulation()->create([
                'patient_id' => $patient->id,
                'physician_id' => $physician->id,
            ]);
        });
        $this->travelBack();

        $response = $this->actingAs($caller)->getJson(route('dashboard.patients.summary', $patient));

        $response->assertOk();
        $items = $response->json('latest_consultations');
        expect($items)->toHaveCount(5);

        $expectedUuids = $consultations->reverse()->take(5)->pluck('uuid')->values()->all();
        expect(collect($items)->pluck('uuid')->all())->toBe($expectedUuids);
    });

    it('reflects can.update and can.delete only for the caller\'s own consultations', function () {
        $caller = User::factory()->withPermissions(
            Permission::PatientsView,
            Permission::ConsultationsView,
            Permission::ConsultationsUpdate,
            Permission::ConsultationsDelete,
        )->create();
        $otherPhysician = User::factory()->create();
        $patient = Patient::factory()->create();

        $owned = MedicalConsultation::factory()->withoutRegulation()->create([
            'patient_id' => $patient->id,
            'physician_id' => $caller->id,
        ]);
        $notOwned = MedicalConsultation::factory()->withoutRegulation()->create([
            'patient_id' => $patient->id,
            'physician_id' => $otherPhysician->id,
        ]);

        $response = $this->actingAs($caller)->getJson(route('dashboard.patients.summary', $patient));

        $items = collect($response->json('latest_consultations'))->keyBy('uuid');

        expect($items[$owned->uuid]['can'])->toBe(['update' => true, 'delete' => true])
            ->and($items[$notOwned->uuid]['can'])->toBe(['update' => false, 'delete' => false]);
    });
});

describe('latest consultations dashboard', function () {
    it('requires consultations.view', function () {
        $caller = User::factory()->create();

        $response = $this->actingAs($caller)->get(route('dashboard.consultations.latest'));

        $response->assertForbidden();
    });

    it("returns only the authenticated user's own consultations, newest first, capped at 10", function () {
        $caller = User::factory()->withPermissions(Permission::ConsultationsView)->create();
        $otherPhysician = User::factory()->create();

        $base = now();
        $ownConsultations = collect(range(0, 11))->map(function (int $minute) use ($base, $caller) {
            $this->travelTo($base->copy()->addMinutes($minute));

            return MedicalConsultation::factory()->withoutRegulation()->create(['physician_id' => $caller->id]);
        });

        $this->travelTo($base->copy()->addMinutes(100));
        $othersConsultation = MedicalConsultation::factory()->withoutRegulation()->create(['physician_id' => $otherPhysician->id]);
        $this->travelBack();

        $response = $this->actingAs($caller)->getJson(route('dashboard.consultations.latest'));

        $response->assertOk();
        $items = $response->json();
        expect($items)->toHaveCount(10);

        $expectedUuids = $ownConsultations->reverse()->take(10)->pluck('uuid')->values()->all();
        expect(collect($items)->pluck('uuid')->all())->toBe($expectedUuids)
            ->and(collect($items)->pluck('uuid'))->not->toContain($othersConsultation->uuid);
    });
});
