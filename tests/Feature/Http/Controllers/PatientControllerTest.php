<?php

use App\Enums\AilmentType;
use App\Enums\BloodType;
use App\Enums\ContraceptiveMethod;
use App\Enums\KinshipType;
use App\Enums\MaritalStatus;
use App\Enums\SexAtBirth;
use App\Models\Neighborhood;
use App\Models\Patient;
use App\Models\PatientAilment;
use App\Models\PatientContactInformation;
use App\Models\PatientEmergencyContact;
use App\Models\PatientGynecologicalHistory;
use App\Models\PatientOtherAilment;
use App\Models\ZipCode;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Inertia\Support\SessionKey;

/**
 * Build a valid, self-contained registration aggregate. Every unique field
 * is regenerated on each call so repeated submissions in the same test do
 * not collide on stage-03a's unique constraints.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function validRegistrationPayload(array $overrides = []): array
{
    $zipCode = ZipCode::query()->inRandomOrder()->first() ?? ZipCode::factory()->create();
    $neighborhood = Neighborhood::query()->where('zip_code', $zipCode->code)->first()
        ?? Neighborhood::factory()->create(['zip_code' => $zipCode->code]);

    $payload = [
        'patient' => [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'second_last_name' => fake()->lastName(),
            'birth_date' => '1990-05-10',
            'sex_at_birth' => SexAtBirth::Male->value,
            'marital_status' => MaritalStatus::Single->value,
            'blood_type' => BloodType::OPositive->value,
            'enrollment_id' => null,
            'enrollment_number' => null,
            'external_enrollment' => fake()->unique()->bothify('EXT-#####'),
            'family_medical_unit_id' => null,
            'other_family_medical_unit' => fake()->company(),
            'social_security_number' => fake()->unique()->numerify('###########'),
        ],
        'contact_information' => [
            'address' => fake()->streetAddress(),
            'phone_number' => fake()->unique()->e164PhoneNumber(),
            'personal_email' => fake()->unique()->safeEmail(),
            'institutional_email' => null,
            'zip_code' => $zipCode->code,
            'neighborhood_id' => $neighborhood->id,
        ],
        'emergency_contacts' => [
            [
                'name' => fake()->name(),
                'phone_number' => fake()->unique()->e164PhoneNumber(),
                'kinship_type' => KinshipType::Parent->value,
            ],
        ],
        'ailments' => [
            [
                'ailment_type' => AilmentType::Diabetes->value,
                'diagnosed_at' => '2015-01-01',
                'treatment_notes' => null,
            ],
        ],
        'other_ailments' => null,
        'gynecological_history' => null,
    ];

    return array_replace_recursive($payload, $overrides);
}

/**
 * A valid gynecological history block, for the Female-only test cases.
 *
 * @return array<string, mixed>
 */
function validGynecologicalHistory(): array
{
    return [
        'menarche' => 12,
        'has_cramps' => true,
        'is_cycle_regular' => true,
        'cycle_intensity' => 5,
        'cycle_duration' => 5,
        'cycle_flow_level' => 3,
        'last_cycle_date' => '2026-08-01',
        'sexual_activity_start_age' => 18,
        'contraceptive_method' => ContraceptiveMethod::Condom->value,
        'last_pap_smear_date' => '2025-01-01',
        'last_pap_smear_was_positive' => false,
        'pregnancies' => 2,
        'vaginal_deliveries' => 1,
        'cesareans' => 0,
        'abortions' => 0,
    ];
}

describe('anonymous public access', function () {
    it('lets a guest view the registration metadata', function () {
        $this->get(route('patients.register'))->assertOk();
    });

    it('lets a guest submit a valid aggregate without any staff authorization', function () {
        $response = $this->post(route('patients.register.store'), validRegistrationPayload());

        $response->assertRedirect(route('patients.register'));
        $response->assertInertiaFlash('registrationSuccess', true);
        expect(Patient::query()->count())->toBe(1);
    });

    it('keeps both registration routes outside the auth and can middleware groups', function () {
        $getRoute = Route::getRoutes()->getByName('patients.register');
        $postRoute = Route::getRoutes()->getByName('patients.register.store');

        expect($getRoute->gatherMiddleware())->not->toContain('auth')
            ->and($postRoute->gatherMiddleware())->not->toContain('auth')
            ->and(collect($postRoute->gatherMiddleware())->filter(fn (string $m) => str_starts_with($m, 'can:')))->toBeEmpty();
    });

    it('keeps the POST route inside the web CSRF verification stack with no bootstrap exemption', function () {
        // Resolving the HTTP kernel syncs the configured middleware groups
        // onto the router; without a dispatched request first, the "web"
        // group has not been synced yet and would appear empty.
        app(Kernel::class);

        $route = Route::getRoutes()->getByName('patients.register.store');
        $resolvedMiddleware = app('router')->gatherRouteMiddleware($route);

        expect($resolvedMiddleware)->toContain(PreventRequestForgery::class);

        $csrfMiddleware = app(PreventRequestForgery::class);
        expect($csrfMiddleware->getExcludedPaths())->not->toContain('patients/register');
    });
});

describe('registration abuse throttling', function () {
    it('allows five submissions per minute, throttles the sixth, and leaves GET unaffected', function () {
        foreach (range(1, 5) as $attempt) {
            $response = $this->post(route('patients.register.store'), validRegistrationPayload());
            expect($response->status())->not->toBe(429);
        }

        $sixthResponse = $this->post(route('patients.register.store'), validRegistrationPayload());
        $sixthResponse->assertStatus(429);

        $this->get(route('patients.register'))->assertOk();
    });
});

describe('unknown and privileged key rejection', function () {
    it('rejects unexpected keys at every nested level and persists nothing', function () {
        $payload = validRegistrationPayload();
        $payload['unexpected_root'] = 'not-allowed';
        $payload['patient']['id'] = 999999;
        $payload['contact_information']['patient_id'] = 999999;
        $payload['emergency_contacts'][0]['patient_id'] = 999999;
        $payload['ailments'][0]['patient_id'] = 999999;
        $payload['other_ailments'] = ['surgeries' => 'appendectomy', 'patient_id' => 999999];

        $response = $this->post(route('patients.register.store'), $payload);

        $response->assertSessionHasErrors([
            'unexpected_root',
            'patient.id',
            'contact_information.patient_id',
            'emergency_contacts.0.patient_id',
            'ailments.0.patient_id',
            'other_ailments.patient_id',
        ]);
        expect(Patient::query()->count())->toBe(0);
    });

    it('rejects an unexpected key inside gynecological_history', function () {
        $payload = validRegistrationPayload([
            'patient' => ['sex_at_birth' => SexAtBirth::Female->value],
            'gynecological_history' => [...validGynecologicalHistory(), 'patient_id' => 999999],
        ]);

        $response = $this->post(route('patients.register.store'), $payload);

        $response->assertSessionHasErrors(['gynecological_history.patient_id']);
        expect(Patient::query()->count())->toBe(0);
    });
});

describe('sensitive-input protection on validation failure', function () {
    it('never flashes the six aggregate roots as old input', function () {
        $payload = validRegistrationPayload(['patient' => ['first_name' => '']]);

        $response = $this->post(route('patients.register.store'), $payload);

        $response->assertSessionHasErrors();
        $oldInput = session()->get('_old_input', []);
        expect($oldInput)->not->toHaveKeys([
            'patient',
            'contact_information',
            'emergency_contacts',
            'ailments',
            'other_ailments',
            'gynecological_history',
        ]);
    });

    it('never reports the validation failure, so no payload-bearing log entry is produced', function () {
        Exceptions::fake();

        $payload = validRegistrationPayload(['patient' => ['first_name' => '']]);
        $this->post(route('patients.register.store'), $payload);

        Exceptions::assertNothingReported();
    });
});

describe('response privacy', function () {
    it('exposes only the single boolean success flash, nothing else', function () {
        $response = $this->post(route('patients.register.store'), validRegistrationPayload());

        $response->assertSessionHas(SessionKey::FLASH_DATA, ['registrationSuccess' => true]);
    });

    it('exposes only a generic error on a forced later-relation failure, never patient ids or medical values', function () {
        Exceptions::fake();
        PatientGynecologicalHistory::creating(function (): void {
            throw new RuntimeException('forced gynecological history failure');
        });

        $payload = validRegistrationPayload([
            'patient' => ['sex_at_birth' => SexAtBirth::Female->value],
            'gynecological_history' => validGynecologicalHistory(),
        ]);

        $response = $this->post(route('patients.register.store'), $payload);

        $response->assertSessionHasErrors(['registration']);
        $errorMessage = session('errors')->get('registration')[0];

        expect($errorMessage)->not->toContain($payload['patient']['social_security_number'])
            ->and($errorMessage)->not->toContain($payload['patient']['first_name']);
        Exceptions::assertReported(RuntimeException::class);
    });
});

describe('atomic aggregate persistence', function () {
    it('rolls back every earlier insert when a later relation fails, and reports the exception', function () {
        Exceptions::fake();
        PatientGynecologicalHistory::creating(function (): void {
            throw new RuntimeException('forced gynecological history failure');
        });

        $payload = validRegistrationPayload([
            'patient' => ['sex_at_birth' => SexAtBirth::Female->value],
            'gynecological_history' => validGynecologicalHistory(),
        ]);

        $this->post(route('patients.register.store'), $payload);

        Exceptions::assertReported(RuntimeException::class);
        expect(Patient::query()->count())->toBe(0)
            ->and(PatientContactInformation::query()->count())->toBe(0)
            ->and(PatientEmergencyContact::query()->count())->toBe(0)
            ->and(PatientAilment::query()->count())->toBe(0)
            ->and(PatientOtherAilment::query()->count())->toBe(0)
            ->and(PatientGynecologicalHistory::query()->count())->toBe(0);
    });

    it('persists the complete aggregate atomically for a valid submission', function () {
        $payload = validRegistrationPayload([
            'patient' => ['sex_at_birth' => SexAtBirth::Female->value],
            'other_ailments' => ['surgeries' => 'Appendectomy', 'allergies' => null, 'others' => null],
            'gynecological_history' => validGynecologicalHistory(),
        ]);

        $this->post(route('patients.register.store'), $payload);

        $patient = Patient::query()->sole();

        expect($patient->contactInformation)->not->toBeNull()
            ->and($patient->emergencyContacts)->toHaveCount(1)
            ->and($patient->ailments)->toHaveCount(1)
            ->and($patient->otherAilments)->not->toBeNull()
            ->and($patient->gynecologicalHistory)->not->toBeNull();
    });
});
