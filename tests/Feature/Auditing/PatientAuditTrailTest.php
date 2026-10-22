<?php

use App\Enums\SexAtBirth;
use App\Models\Audit;
use App\Models\Enrollment;
use App\Models\FamilyMedicalUnit;
use App\Models\Neighborhood;
use App\Models\Patient;
use App\Models\PatientContactInformation;
use App\Models\User;
use App\Services\Patients\PatientWriter;
use Illuminate\Support\Collection;
use Tests\Support\PatientFixtures;

/**
 * @return Collection<int, Audit>
 */
function trailAudits(Patient $patient): Collection
{
    return Audit::query()->where('tags', 'patient:'.$patient->id)->orderBy('id')->get();
}

it('audits an anonymous registration with every model, one batch, a null user and the request origin', function () {
    $payload = PatientFixtures::payload([
        'patient' => ['sex_at_birth' => SexAtBirth::Female->value],
        'gynecological_history' => PatientFixtures::gynecologicalHistory(),
    ]);

    $this->withHeader('User-Agent', 'VitalEase-Test/1.0')
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
        ->post(route('patients.register.store'), $payload)
        ->assertSessionHasNoErrors();

    $patient = Patient::query()->where('social_security_number', $payload['patient']['social_security_number'])->firstOrFail();
    $audits = trailAudits($patient);

    expect($audits)->toHaveCount(8)
        ->and($audits->pluck('event')->unique()->all())->toBe(['created'])
        ->and($audits->pluck('batch_id')->unique())->toHaveCount(1)
        ->and($audits->first()->batch_id)->not->toBeNull()
        ->and($audits->pluck('user_id')->unique()->all())->toBe([null])
        ->and($audits->pluck('user_type')->unique()->all())->toBe([null])
        ->and($audits->pluck('ip_address')->unique()->all())->toBe(['203.0.113.7'])
        ->and($audits->pluck('user_agent')->unique()->all())->toBe(['VitalEase-Test/1.0'])
        ->and($audits->pluck('tags')->unique()->all())->toBe(['patient:'.$patient->id]);
});

it('tags a child edit with its patient and the acting user', function () {
    $patient = PatientFixtures::register();
    $actor = User::factory()->create();
    $this->actingAs($actor);

    PatientContactInformation::query()->findOrFail($patient->id)->update(['address' => 'Avenida Central 10']);

    $audit = trailAudits($patient)->last();

    expect($audit->auditable_type)->toBe(PatientContactInformation::class)
        ->and($audit->event)->toBe('updated')
        ->and($audit->tags)->toBe('patient:'.$patient->id)
        ->and($audit->user_id)->toBe($actor->id)
        ->and($audit->new_values)->toMatchArray(['address' => 'Avenida Central 10']);
});

it('keeps the labels of the catalog values of each side even after the catalog is renamed', function () {
    $oldEnrollment = Enrollment::factory()->create(['name' => 'Ordinario']);
    $newEnrollment = Enrollment::factory()->create(['name' => 'Voluntario']);
    $oldUnit = FamilyMedicalUnit::factory()->create(['name' => 'UMF 1']);
    $newUnit = FamilyMedicalUnit::factory()->create(['name' => 'UMF 2']);
    $oldNeighborhood = Neighborhood::factory()->create(['name' => 'Centro', 'zip_code' => PatientFixtures::payload()['contact_information']['zip_code']]);
    $newNeighborhood = Neighborhood::factory()->create(['name' => 'Reforma']);

    $patient = PatientFixtures::register([
        'patient' => ['enrollment_id' => $oldEnrollment->id, 'enrollment_number' => '111', 'external_enrollment' => null, 'family_medical_unit_id' => $oldUnit->id, 'other_family_medical_unit' => null],
        'contact_information' => ['neighborhood_id' => $oldNeighborhood->id],
    ]);
    $this->actingAs(User::factory()->create());
    $edit = PatientFixtures::editPayload($patient);
    $edit['patient'] = [...$edit['patient'], 'enrollment_id' => $newEnrollment->id, 'family_medical_unit_id' => $newUnit->id];
    $edit['contact_information'] = [...$edit['contact_information'], 'zip_code' => $newNeighborhood->zip_code, 'neighborhood_id' => $newNeighborhood->id];
    app(PatientWriter::class)->update($patient, $edit);

    $oldEnrollment->update(['name' => 'Renombrado']);
    $oldUnit->update(['name' => 'UMF renombrada']);
    $oldNeighborhood->update(['name' => 'Colonia renombrada']);

    $audits = trailAudits($patient);
    $patientAudit = $audits->where('auditable_type', Patient::class)->firstWhere('event', 'updated');
    $contactAudit = $audits->where('auditable_type', PatientContactInformation::class)->firstWhere('event', 'updated');

    expect($patientAudit->old_values)->toMatchArray(['enrollment' => 'Ordinario', 'family_medical_unit' => 'UMF 1'])
        ->and($patientAudit->new_values)->toMatchArray(['enrollment' => 'Voluntario', 'family_medical_unit' => 'UMF 2'])
        ->and($patientAudit->old_values)->not->toHaveKeys(['enrollment_id', 'family_medical_unit_id'])
        ->and($contactAudit->old_values['neighborhood'])->toBe("Centro (CP {$oldNeighborhood->zip_code})")
        ->and($contactAudit->new_values['neighborhood'])->toBe("Reforma (CP {$newNeighborhood->zip_code})")
        ->and($contactAudit->old_values)->not->toHaveKey('neighborhood_id');
});

it('stores the social security number, emails and phones in the clear', function () {
    $patient = PatientFixtures::register();
    $this->actingAs(User::factory()->create());
    $edit = PatientFixtures::editPayload($patient);
    $edit['patient']['social_security_number'] = '12345678901';
    $edit['contact_information'] = [...$edit['contact_information'], 'phone_number' => '+5215550001111', 'personal_email' => 'clear@example.test', 'institutional_email' => 'clear@imss.test'];

    app(PatientWriter::class)->update($patient, $edit);

    $audits = trailAudits($patient)->where('event', 'updated');

    expect($audits->firstWhere('auditable_type', Patient::class)->new_values)->toMatchArray(['social_security_number' => '12345678901'])
        ->and($audits->firstWhere('auditable_type', PatientContactInformation::class)->new_values)
        ->toMatchArray(['phone_number' => '+5215550001111', 'personal_email' => 'clear@example.test', 'institutional_email' => 'clear@imss.test']);
});

it('never tracks timestamps, row identifiers or the disable columns as values', function () {
    $patient = PatientFixtures::register();

    $keys = trailAudits($patient)
        ->flatMap(fn (Audit $audit): array => [...array_keys($audit->old_values ?? []), ...array_keys($audit->new_values ?? [])])
        ->unique();

    expect($keys->intersect(['id', 'uuid', 'created_at', 'updated_at', 'disabled_at', 'disabled_by_id', 'disable_reason', 'is_disabled', 'patient_id']))->toBeEmpty();
});

it('makes audit rows immutable for the application', function () {
    $patient = PatientFixtures::register();
    $audit = trailAudits($patient)->first();

    expect(fn () => $audit->update(['event' => 'tampered']))->toThrow(LogicException::class)
        ->and(fn () => $audit->delete())->toThrow(LogicException::class)
        ->and($audit->fresh()->event)->toBe('created');
});
