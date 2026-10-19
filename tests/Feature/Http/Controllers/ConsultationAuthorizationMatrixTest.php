<?php

use App\Enums\MedicalClassification;
use App\Enums\MedicalState;
use App\Enums\Permission;
use App\Models\MedicalConsultation;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every consultation action, for every kind of actor, against active and
 * voided consultations. Outcomes: `allow` (request accepted), `forbid`
 * (403), `notfound` (404: the route never resolves a voided consultation
 * for edit/update/void) and `login` (guest redirected to login).
 */

/**
 * @return array<string, mixed>
 */
function matrixUpdatePayload(): array
{
    return [
        'consultation' => ['current_condition' => 'Dolor abdominal', 'diagnosis' => 'Gastritis'],
        'condition' => MedicalState::Good->value,
        'prognosis' => MedicalState::Good->value,
        'medical_classification' => MedicalClassification::Trauma->value,
        'treatment' => [],
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
}

function matrixActor(string $actor): ?User
{
    return match ($actor) {
        'guest' => null,
        'no-permission' => User::factory()->create(),
        'owner', 'non-owner' => User::factory()->withPermissions(Permission::ConsultationsView, Permission::ConsultationsUpdate, Permission::ConsultationsVoid)->create(),
        'void-any' => User::factory()->withPermissions(Permission::ConsultationsView, Permission::ConsultationsVoidAny)->create(),
        'view-voided' => User::factory()->withPermissions(Permission::ConsultationsView, Permission::ConsultationsViewVoided)->create(),
        'audit' => User::factory()->withPermissions(Permission::ConsultationsView, Permission::ConsultationsAudit)->create(),
        'super-admin' => User::factory()->superAdmin()->create(),
    };
}

/**
 * Who owns the consultation an action targets. The `non-owner` actor never
 * owns it; for the plain view/update actions only the `owner` actor does.
 */
function matrixConsultation(string $action, string $actor, ?User $user): MedicalConsultation
{
    $owns = match ($action) {
        'view active', 'view voided', 'update active' => $actor === 'owner',
        'void own', 'restore own' => $actor !== 'non-owner',
        default => false,
    };

    $factory = MedicalConsultation::factory()->withoutRegulation();

    if (in_array($action, ['view voided', 'update voided', 'restore own', 'restore others', 'history voided'], true)) {
        $factory = $factory->voided();
    }

    return $factory->create($owns && $user !== null ? ['physician_id' => $user->id] : []);
}

/**
 * @return TestResponse<Response>
 */
function matrixAttempt(string $action, MedicalConsultation $consultation, ?User $user)
{
    $test = test();

    if ($user !== null) {
        $test->actingAs($user);
    }

    return match ($action) {
        'view active', 'view voided' => $test->get(route('consultations.show', $consultation)),
        'update active', 'update voided' => $test->put(route('consultations.update', $consultation), matrixUpdatePayload()),
        'void own', 'void others' => $test->post(route('consultations.void', $consultation), ['reason' => 'Registro duplicado']),
        'restore own', 'restore others' => $test->post(route('consultations.restore', $consultation), ['reason' => 'Se anuló por error']),
        'history active', 'history voided' => $test->get(route('consultations.history', $consultation)),
    };
}

/**
 * @return array<string, array{string, string, string}>
 */
function consultationAuthorizationMatrix(): array
{
    $actions = [
        'view active', 'view voided', 'update active', 'update voided', 'void own', 'void others',
        'restore own', 'restore others', 'history active', 'history voided',
    ];

    $expected = [
        'guest' => array_fill_keys($actions, 'login'),
        'no-permission' => [...array_fill_keys($actions, 'forbid'), 'update voided' => 'notfound'],
        'owner' => [
            'view active' => 'allow', 'view voided' => 'allow', 'update active' => 'allow', 'update voided' => 'notfound',
            'void own' => 'allow', 'void others' => 'forbid', 'restore own' => 'allow', 'restore others' => 'forbid',
            'history active' => 'forbid', 'history voided' => 'forbid',
        ],
        'non-owner' => [
            'view active' => 'allow', 'view voided' => 'forbid', 'update active' => 'forbid', 'update voided' => 'notfound',
            'void own' => 'forbid', 'void others' => 'forbid', 'restore own' => 'forbid', 'restore others' => 'forbid',
            'history active' => 'forbid', 'history voided' => 'forbid',
        ],
        'void-any' => [
            'view active' => 'allow', 'view voided' => 'allow', 'update active' => 'forbid', 'update voided' => 'notfound',
            'void own' => 'allow', 'void others' => 'allow', 'restore own' => 'allow', 'restore others' => 'allow',
            'history active' => 'forbid', 'history voided' => 'forbid',
        ],
        'view-voided' => [
            'view active' => 'allow', 'view voided' => 'allow', 'update active' => 'forbid', 'update voided' => 'notfound',
            'void own' => 'forbid', 'void others' => 'forbid', 'restore own' => 'forbid', 'restore others' => 'forbid',
            'history active' => 'forbid', 'history voided' => 'forbid',
        ],
        'audit' => [
            'view active' => 'allow', 'view voided' => 'forbid', 'update active' => 'forbid', 'update voided' => 'notfound',
            'void own' => 'forbid', 'void others' => 'forbid', 'restore own' => 'forbid', 'restore others' => 'forbid',
            'history active' => 'allow', 'history voided' => 'allow',
        ],
        'super-admin' => [
            'view active' => 'allow', 'view voided' => 'allow', 'update active' => 'allow', 'update voided' => 'notfound',
            'void own' => 'allow', 'void others' => 'allow', 'restore own' => 'allow', 'restore others' => 'allow',
            'history active' => 'allow', 'history voided' => 'allow',
        ],
    ];

    $cases = [];

    foreach ($expected as $actor => $row) {
        foreach ($row as $action => $outcome) {
            $cases["{$actor} / {$action}"] = [$actor, $action, $outcome];
        }
    }

    return $cases;
}

it('applies the consultation authorization matrix', function (string $actor, string $action, string $outcome) {
    $user = matrixActor($actor);
    $consultation = matrixConsultation($action, $actor, $user);

    $response = matrixAttempt($action, $consultation, $user);

    match ($outcome) {
        'allow' => expect($response->getStatusCode())->toBeLessThan(400),
        'forbid' => $response->assertForbidden(),
        'notfound' => $response->assertNotFound(),
        'login' => $response->assertRedirect(route('auth.login')),
    };
})->with(consultationAuthorizationMatrix());

it('never lets a state-invalid request through, even for super-admin', function (string $action) {
    $admin = User::factory()->superAdmin()->create();
    $active = MedicalConsultation::factory()->withoutRegulation()->create();
    $voided = MedicalConsultation::factory()->withoutRegulation()->voided()->create();

    $response = match ($action) {
        'void a voided consultation' => $this->actingAs($admin)->post(route('consultations.void', $voided), ['reason' => 'Otra vez']),
        'edit a voided consultation' => $this->actingAs($admin)->get(route('consultations.edit', $voided)),
        'update a voided consultation' => $this->actingAs($admin)->put(route('consultations.update', $voided), matrixUpdatePayload()),
        'restore an active consultation' => $this->actingAs($admin)->post(route('consultations.restore', $active), ['reason' => 'Sin necesidad']),
    };

    if ($action === 'restore an active consultation') {
        $response->assertSessionHasErrors('consultation');
        expect($active->fresh()->trashed())->toBeFalse();

        return;
    }

    $response->assertNotFound();
    expect($voided->refresh()->void_reason)->toBe('Registro duplicado');
})->with([
    'void a voided consultation',
    'edit a voided consultation',
    'update a voided consultation',
    'restore an active consultation',
]);
