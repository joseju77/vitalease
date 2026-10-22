<?php

use App\Models\Audit;
use App\Models\Enrollment;
use App\Models\Patient;
use App\Models\User;
use App\Services\Patients\PatientWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\PatientFixtures;

/**
 * An existing patient holding every unique value, plus its enrollment.
 *
 * @return array{Patient, Enrollment}
 */
function duplicatesExistingPatient(bool $disabled = false): array
{
    $enrollment = Enrollment::factory()->create();
    $overrides = [
        'patient' => [
            'enrollment_id' => $enrollment->id,
            'enrollment_number' => '555000111',
            'external_enrollment' => null,
            'social_security_number' => '99999999999',
        ],
        'contact_information' => ['institutional_email' => 'existing@imss.test'],
    ];

    $patient = PatientFixtures::register($overrides);

    if ($disabled) {
        $patient->forceFill(['disabled_at' => now(), 'disabled_by_id' => User::factory()->create()->id, 'disable_reason' => 'Duplicado'])->saveQuietly();
    }

    return [$patient, $enrollment];
}

/**
 * The colliding value of each unique field, as a payload override.
 *
 * @return array<string, array<string, mixed>>
 */
function duplicateCollisions(Enrollment $enrollment): array
{
    return [
        'social security number' => ['patient' => ['social_security_number' => '99999999999']],
        'enrollment pair' => ['patient' => ['enrollment_id' => $enrollment->id, 'enrollment_number' => '555000111', 'external_enrollment' => null]],
        'institutional email' => ['contact_information' => ['institutional_email' => 'existing@imss.test']],
    ];
}

describe('public registration', function () {
    it('answers every unique collision with the same generic message and writes nothing', function (string $field, bool $againstDisabled) {
        [, $enrollment] = duplicatesExistingPatient($againstDisabled);
        $patients = Patient::query()->count();
        $audits = Audit::query()->count();

        $this->post(route('patients.register.store'), PatientFixtures::payload(duplicateCollisions($enrollment)[$field]))
            ->assertSessionHasErrors('registration');

        $message = session('errors')->first('registration');

        expect($message)->toBe(__('modules/patients/registration.duplicate_registration'))
            ->and($message)->not->toContain('99999999999', '555000111', 'existing@imss.test')
            ->and(Patient::query()->count())->toBe($patients)
            ->and(Audit::query()->count())->toBe($audits);
    })->with([
        'social security number' => ['social security number', false],
        'enrollment pair' => ['enrollment pair', false],
        'institutional email' => ['institutional email', false],
        'social security number of a disabled patient' => ['social security number', true],
        'enrollment pair of a disabled patient' => ['enrollment pair', true],
        'institutional email of a disabled patient' => ['institutional email', true],
    ]);

    it('answers a race between the validation and the write with the same generic message', function () {
        $payload = PatientFixtures::payload();
        $competitor = PatientFixtures::payload();
        $competitor['patient']['social_security_number'] = $payload['patient']['social_security_number'];

        // A concurrent registration wins the unique key right before this one writes.
        Event::listen('eloquent.creating: '.Patient::class, function () use ($competitor): void {
            static $fired = false;

            if ($fired) {
                return;
            }

            $fired = true;
            DB::table('patients')->insert([
                ...collect($competitor['patient'])->except(['enrollment_id'])->all(),
                'uuid' => (string) Str::uuid7(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->post(route('patients.register.store'), $payload)->assertSessionHasErrors('registration');

        expect(session('errors')->first('registration'))->toBe(__('modules/patients/registration.duplicate_registration'));
    });
});

describe('staff edit', function () {
    it('names the offending field for every unique collision and changes nothing', function (string $field, string $errorKey, bool $againstDisabled) {
        [, $enrollment] = duplicatesExistingPatient($againstDisabled);
        $patient = PatientFixtures::register();
        $staff = User::factory()->superAdmin()->create();
        $audits = Audit::query()->count();
        $overrides = duplicateCollisions($enrollment)[$field];
        $current = PatientFixtures::editPayload($patient);
        $payload = array_replace_recursive($current, $overrides);

        $this->actingAs($staff)->put(route('patients.update', $patient), $payload)->assertSessionHasErrors($errorKey);

        $message = session('errors')->first($errorKey);

        expect($message)->not->toBe(__('modules/patients/registration.duplicate_registration'))
            ->and($message)->toContain('Ya existe un paciente')
            ->and(PatientFixtures::editPayload($patient))->toEqual($current)
            ->and(Audit::query()->count())->toBe($audits);
    })->with([
        'social security number' => ['social security number', 'patient.social_security_number', false],
        'enrollment pair' => ['enrollment pair', 'patient.enrollment_number', false],
        'institutional email' => ['institutional email', 'contact_information.institutional_email', false],
        'social security number of a disabled patient' => ['social security number', 'patient.social_security_number', true],
        'enrollment pair of a disabled patient' => ['enrollment pair', 'patient.enrollment_number', true],
        'institutional email of a disabled patient' => ['institutional email', 'contact_information.institutional_email', true],
    ]);

    it('accepts the patient\'s own social security number, enrollment pair and institutional email', function () {
        [$patient] = duplicatesExistingPatient();
        $staff = User::factory()->superAdmin()->create();
        $audits = Audit::query()->count();

        $this->actingAs($staff)->put(route('patients.update', $patient), PatientFixtures::editPayload($patient))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('patients.show', $patient));

        expect(Audit::query()->count())->toBe($audits);
    });

    it('maps a unique race at the write to the same field error and writes nothing', function (string $field, string $errorKey) {
        [, $enrollment] = duplicatesExistingPatient();
        $patient = PatientFixtures::register();
        $current = PatientFixtures::editPayload($patient);
        $audits = Audit::query()->count();

        // The writer is called directly: the request-level uniqueness rules are bypassed, as in a race.
        $caught = null;

        try {
            app(PatientWriter::class)->update($patient, array_replace_recursive($current, duplicateCollisions($enrollment)[$field]));
        } catch (ValidationException $exception) {
            $caught = $exception;
        }

        expect($caught)->not->toBeNull()
            ->and(array_keys($caught->errors()))->toBe([$errorKey])
            ->and($caught->errors()[$errorKey][0])->toContain('Ya existe un paciente')
            ->and(PatientFixtures::editPayload($patient))->toEqual($current)
            ->and(Audit::query()->count())->toBe($audits);
    })->with([
        'social security number' => ['social security number', 'patient.social_security_number'],
        'enrollment pair' => ['enrollment pair', 'patient.enrollment_number'],
        'institutional email' => ['institutional email', 'contact_information.institutional_email'],
    ]);
});
