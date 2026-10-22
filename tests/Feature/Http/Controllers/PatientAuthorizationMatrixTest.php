<?php

use App\Enums\MedicalClassification;
use App\Enums\MedicalState;
use App\Enums\Permission;
use App\Models\Audit;
use App\Models\MedicalConsultation;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\PatientFixtures;

/**
 * Every patient action, for every kind of actor, against active and
 * disabled patients. Outcomes: `allow` (request accepted), `forbid` (403),
 * `invalid` (422-style rejection by the state guard: redirect back with an
 * error on `patient`) and `login` (guest redirected to login).
 */

/**
 * @return TestResponse<Response>
 */
function patientMatrixAttempt(string $action, Patient $patient, ?User $user)
{
    $test = test();

    if ($user !== null) {
        $test->actingAs($user);
    }

    return match ($action) {
        'index' => $test->get(route('patients.index')),
        'show active', 'show disabled' => $test->get(route('patients.show', $patient)),
        'edit active', 'edit disabled' => $test->get(route('patients.edit', $patient)),
        'update active', 'update disabled' => $test->put(route('patients.update', $patient), PatientFixtures::editPayload($patient, [
            'patient' => [...PatientFixtures::editPayload($patient)['patient'], 'first_name' => 'Renombrado'],
        ])),
        'disable active', 'disable disabled' => $test->post(route('patients.disable', $patient), ['reason' => 'Registro duplicado']),
        'enable active', 'enable disabled' => $test->post(route('patients.enable', $patient), ['reason' => 'Se deshabilito por error']),
        'history active', 'history disabled' => $test->get(route('patients.history', $patient)),
    };
}

/**
 * @return array<string, array{string, string, string}>
 */
function patientAuthorizationMatrix(): array
{
    $actions = [
        'index', 'show active', 'show disabled', 'edit active', 'edit disabled', 'update active', 'update disabled',
        'disable active', 'disable disabled', 'enable disabled', 'enable active', 'history active', 'history disabled',
    ];

    // Which actors may perform each action; super-admin is listed explicitly when a state guard answers instead.
    $allowed = [
        'index' => ['view', 'update', 'disable', 'audit', 'super-admin'],
        'show active' => ['view', 'update', 'disable', 'audit', 'super-admin'],
        'show disabled' => ['view', 'update', 'disable', 'audit', 'super-admin'],
        'edit active' => ['update', 'super-admin'],
        'edit disabled' => [],
        'update active' => ['update', 'super-admin'],
        'update disabled' => [],
        'disable active' => ['disable', 'super-admin'],
        'disable disabled' => [],
        'enable disabled' => ['disable', 'super-admin'],
        'enable active' => [],
        'history active' => ['audit', 'super-admin'],
        'history disabled' => ['audit', 'super-admin'],
    ];

    // Super-admin bypasses the policy: the service answers a wrong state with a validation error.
    $invalid = ['update disabled', 'disable disabled', 'enable active'];

    $cases = [];

    foreach (['guest', 'no-permission', 'view', 'update', 'disable', 'audit', 'super-admin'] as $actor) {
        foreach ($actions as $action) {
            $outcome = match (true) {
                $actor === 'guest' => 'login',
                $actor === 'super-admin' && in_array($action, $invalid, true) => 'invalid',
                in_array($actor, $allowed[$action], true) => 'allow',
                default => 'forbid',
            };

            $cases["{$actor} / {$action}"] = [$actor, $action, $outcome];
        }
    }

    return $cases;
}

it('applies the patient authorization matrix and denied attempts change nothing', function (string $actor, string $action, string $outcome) {
    $user = PatientFixtures::actor($actor);
    $patient = str_ends_with($action, ' disabled') ? PatientFixtures::registerDisabled() : PatientFixtures::register();
    $patient = Patient::query()->whereKey($patient->id)->first();
    $before = [$patient->getAttributes(), Audit::query()->count(), $patient->contactInformation()->first()->only(['address', 'phone_number'])];

    $response = patientMatrixAttempt($action, $patient, $user);

    match ($outcome) {
        'allow' => expect($response->getStatusCode())->toBeLessThan(400),
        'forbid' => $response->assertForbidden(),
        'invalid' => $response->assertSessionHasErrors('patient'),
        'login' => $response->assertRedirect(route('auth.login')),
    };

    if (in_array($outcome, ['forbid', 'invalid', 'login'], true)) {
        $after = Patient::query()->whereKey($patient->id)->first();

        expect([$after->getAttributes(), Audit::query()->count(), $after->contactInformation()->first()->only(['address', 'phone_number'])])->toBe($before);
    }
})->with(patientAuthorizationMatrix());

it('exposes no DELETE route for a patient and leaves it with every child intact', function (string $actor) {
    $patient = PatientFixtures::register();
    $user = PatientFixtures::actor($actor);

    $this->actingAs($user)->delete('/patients/'.$patient->uuid)->assertStatus(405);

    expect(Patient::query()->whereKey($patient->id)->exists())->toBeTrue()
        ->and($patient->emergencyContacts()->count())->toBe(2)
        ->and($patient->ailments()->count())->toBe(2)
        ->and($patient->contactInformation()->exists())->toBeTrue();
})->with(['super-admin', 'disable']);

describe('consultation creation for a disabled patient', function () {
    function disabledPatientConsultationActor(string $actor): ?User
    {
        return match ($actor) {
            'guest' => null,
            'no-permission' => User::factory()->create(),
            'consultations.create' => User::factory()->withPermissions(Permission::ConsultationsCreate)->create(),
            'super-admin' => User::factory()->superAdmin()->create(),
        };
    }

    it('rejects the create page and the store, and creates nothing', function (string $actor, string $page, string $store) {
        $user = disabledPatientConsultationActor($actor);
        $patient = PatientFixtures::registerDisabled();

        if ($user !== null) {
            $this->actingAs($user);
        }

        $payload = [
            'patient_uuid' => $patient->uuid,
            'consultation' => ['current_condition' => 'Dolor abdominal', 'diagnosis' => 'Gastritis'],
            'condition' => MedicalState::Good->value, 'prognosis' => MedicalState::Good->value,
            'medical_classification' => MedicalClassification::Trauma->value, 'treatment' => [],
            'vital_signs' => [
                'weight' => 70, 'height' => 1.7, 'blood_pressure_systolic' => 120, 'blood_pressure_diastolic' => 80,
                'heart_rate' => 72, 'respiratory_rate' => 16, 'temperature' => 37, 'oxygen_saturation' => 98,
                'glasgow' => 15, 'glucose' => 90,
            ],
            'physical_examination' => [
                'neurological' => 'Sin alteraciones.', 'head_neck' => 'Sin alteraciones.',
                'thorax_cardiopulmonary' => 'Sin alteraciones.', 'abdomen' => 'Sin alteraciones.',
                'extremities' => 'Sin alteraciones.', 'cabinet_laboratory' => 'Sin alteraciones.',
            ],
            'regulation' => null,
        ];
        $audits = Audit::query()->count();

        $pageResponse = $this->get(route('consultations.create', ['patient' => $patient->uuid]));
        $storeResponse = $this->post(route('consultations.store'), $payload);

        match ($page) {
            'login' => $pageResponse->assertRedirect(route('auth.login')),
            'forbid' => $pageResponse->assertForbidden(),
        };

        match ($store) {
            'login' => $storeResponse->assertRedirect(route('auth.login')),
            'forbid' => $storeResponse->assertForbidden(),
            'invalid' => $storeResponse->assertSessionHasErrors('patient_uuid'),
        };

        expect(MedicalConsultation::query()->count())->toBe(0)
            ->and(Audit::query()->count())->toBe($audits);
    })->with([
        'guest' => ['guest', 'login', 'login'],
        'no permission' => ['no-permission', 'forbid', 'forbid'],
        'consultations.create only' => ['consultations.create', 'forbid', 'invalid'],
        'super-admin' => ['super-admin', 'forbid', 'invalid'],
    ]);
});
