<?php

use App\Enums\AilmentType;
use App\Enums\KinshipType;
use App\Enums\MaritalStatus;
use App\Enums\SexAtBirth;
use App\Models\Audit;
use App\Models\Enrollment;
use App\Models\FamilyMedicalUnit;
use App\Models\Neighborhood;
use App\Models\Patient;
use App\Models\PatientAilment;
use App\Models\PatientContactInformation;
use App\Models\PatientEmergencyContact;
use App\Models\PatientGynecologicalHistory;
use App\Models\PatientOtherAilment;
use App\Models\User;
use App\Services\Patients\PatientDisabling;
use App\Services\Patients\PatientWriter;
use App\Support\Patients\PatientEditPayload;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\Support\PatientFixtures;

/**
 * @return Collection<int, Audit>
 */
function writerPatientAudits(Patient $patient): Collection
{
    return Audit::query()->where('tags', 'patient:'.$patient->id)->orderBy('id')->get();
}

/**
 * The audits written after a given audit id (the ones one operation wrote).
 *
 * @return Collection<int, Audit>
 */
function writerAuditsSince(Patient $patient, int $lastAuditId): Collection
{
    return writerPatientAudits($patient)->where('id', '>', $lastAuditId)->values();
}

function writerLastAuditId(): int
{
    return (int) Audit::query()->max('id');
}

/**
 * Sort a list of rows by a key so stored and submitted lists compare equal.
 *
 * @param  list<array<string, mixed>>  $rows
 * @return list<array<string, mixed>>
 */
function writerSorted(array $rows, string $key): array
{
    return collect($rows)->sortBy($key)->values()->all();
}

describe('registering', function () {
    it('writes the whole aggregate in one audit batch with a null user and one tag', function () {
        $patient = PatientFixtures::register([
            'patient' => ['sex_at_birth' => SexAtBirth::Female->value],
            'gynecological_history' => PatientFixtures::gynecologicalHistory(),
        ]);

        $audits = writerPatientAudits($patient);

        expect($audits->pluck('auditable_type')->unique()->sort()->values()->all())->toBe(collect([
            Patient::class, PatientContactInformation::class, PatientEmergencyContact::class,
            PatientAilment::class, PatientOtherAilment::class, PatientGynecologicalHistory::class,
        ])->sort()->values()->all())
            ->and($audits)->toHaveCount(1 + 1 + 2 + 2 + 1 + 1)
            ->and($audits->pluck('event')->unique()->all())->toBe(['created'])
            ->and($audits->pluck('batch_id')->unique())->toHaveCount(1)
            ->and($audits->first()->batch_id)->not->toBeNull()
            ->and($audits->pluck('user_id')->unique()->all())->toBe([null])
            ->and($audits->pluck('user_type')->unique()->all())->toBe([null])
            ->and($audits->pluck('tags')->unique()->all())->toBe(['patient:'.$patient->id]);
    });

    it('leaves no patient, child or audit when a later row fails', function () {
        Event::listen('eloquent.creating: '.PatientAilment::class, fn () => throw new RuntimeException('forced failure'));

        expect(fn () => PatientFixtures::register())->toThrow(RuntimeException::class, 'forced failure');

        expect(Patient::query()->count())->toBe(0)
            ->and(PatientContactInformation::query()->count())->toBe(0)
            ->and(PatientEmergencyContact::query()->count())->toBe(0)
            ->and(Audit::query()->count())->toBe(0);
    });
});

describe('editing every section', function () {
    it('persists the submitted state of demographics, contact, contacts, ailments, other ailments and gynecological history', function () {
        $patient = PatientFixtures::register(['patient' => ['sex_at_birth' => SexAtBirth::Female->value]]);
        $enrollment = Enrollment::factory()->create();
        $unit = FamilyMedicalUnit::factory()->create();
        $neighborhood = Neighborhood::factory()->create();
        $this->actingAs(User::factory()->create());

        $submitted = PatientFixtures::editPayload($patient, [
            'patient' => [
                ...PatientFixtures::editPayload($patient)['patient'],
                'first_name' => 'Marina',
                'marital_status' => MaritalStatus::Married->value,
                'enrollment_id' => $enrollment->id,
                'enrollment_number' => '123456789',
                'external_enrollment' => null,
                'family_medical_unit_id' => $unit->id,
                'other_family_medical_unit' => null,
            ],
            'contact_information' => [
                ...PatientFixtures::editPayload($patient)['contact_information'],
                'address' => 'Calle Nueva 45',
                'institutional_email' => 'marina@imss.test',
                'zip_code' => $neighborhood->zip_code,
                'neighborhood_id' => $neighborhood->id,
            ],
            'emergency_contacts' => [
                ['name' => 'Ana Lopez', 'phone_number' => '+5212220000002', 'kinship_type' => KinshipType::Spouse->value],
                ['name' => 'Pedro Gil', 'phone_number' => '+5212220000009', 'kinship_type' => KinshipType::Sibling->value],
            ],
            'ailments' => [
                ['ailment_type' => AilmentType::Hypertension->value, 'diagnosed_at' => '2018-03-04', 'treatment_notes' => 'Nueva dieta'],
                ['ailment_type' => AilmentType::Cancer->value, 'diagnosed_at' => '2020-02-02', 'treatment_notes' => null],
            ],
            'other_ailments' => ['surgeries' => null, 'allergies' => 'Penicilina', 'others' => null],
            'gynecological_history' => PatientFixtures::gynecologicalHistory(),
        ]);

        app(PatientWriter::class)->update($patient, $submitted);

        $stored = PatientEditPayload::for($patient->fresh());

        expect($stored['patient'])->toEqual($submitted['patient'])
            ->and($stored['contact_information'])->toEqual($submitted['contact_information'])
            ->and(writerSorted($stored['emergency_contacts'], 'phone_number'))->toEqual(writerSorted($submitted['emergency_contacts'], 'phone_number'))
            ->and(writerSorted($stored['ailments'], 'ailment_type'))->toEqual(writerSorted($submitted['ailments'], 'ailment_type'))
            ->and($stored['other_ailments'])->toEqual($submitted['other_ailments'])
            ->and($stored['gynecological_history'])->toEqual($submitted['gynecological_history']);
    });

    it('adds, changes and removes emergency contacts matched by phone number, auditing each', function () {
        $patient = PatientFixtures::register();
        $this->actingAs(User::factory()->create());
        $last = writerLastAuditId();

        app(PatientWriter::class)->update($patient, PatientFixtures::editPayload($patient, [
            'emergency_contacts' => [
                ['name' => 'Ana Lopez Ruiz', 'phone_number' => '+5212220000002', 'kinship_type' => KinshipType::Sibling->value],
                ['name' => 'Rosa Diaz', 'phone_number' => '+5212220000008', 'kinship_type' => KinshipType::Child->value],
            ],
        ]));

        $audits = writerAuditsSince($patient, $last)->where('auditable_type', PatientEmergencyContact::class);

        expect($audits->pluck('event')->sort()->values()->all())->toBe(['created', 'deleted', 'updated'])
            ->and($audits->firstWhere('event', 'deleted')->old_values)->toMatchArray(['name' => 'Luis Ramos', 'phone_number' => '+5212220000003'])
            ->and($audits->firstWhere('event', 'updated')->new_values)->toMatchArray(['name' => 'Ana Lopez Ruiz'])
            ->and($audits->firstWhere('event', 'created')->new_values)->toMatchArray(['phone_number' => '+5212220000008'])
            ->and($audits->pluck('tags')->unique()->all())->toBe(['patient:'.$patient->id])
            ->and($patient->emergencyContacts()->pluck('phone_number')->sort()->values()->all())->toBe(['+5212220000002', '+5212220000008']);
    });

    it('updates a changed contact in place with one audit and leaves the untouched one alone', function () {
        $patient = PatientFixtures::register();
        $this->actingAs(User::factory()->create());
        $untouched = $patient->emergencyContacts()->where('phone_number', '+5212220000003')->first();
        $last = writerLastAuditId();

        app(PatientWriter::class)->update($patient, PatientFixtures::editPayload($patient, [
            'emergency_contacts' => [
                ['name' => 'Ana Lopez', 'phone_number' => '+5212220000002', 'kinship_type' => KinshipType::Other->value],
                ['name' => 'Luis Ramos', 'phone_number' => '+5212220000003', 'kinship_type' => KinshipType::Friend->value],
            ],
        ]));

        $audits = writerAuditsSince($patient, $last);

        expect($audits)->toHaveCount(1)
            ->and($audits->first()->event)->toBe('updated')
            ->and($patient->emergencyContacts()->where('phone_number', '+5212220000003')->first()->id)->toBe($untouched->id);
    });

    it('swaps two phone numbers between emergency contacts without violating the unique key', function () {
        $patient = PatientFixtures::register();
        $this->actingAs(User::factory()->create());

        app(PatientWriter::class)->update($patient, PatientFixtures::editPayload($patient, [
            'emergency_contacts' => [
                ['name' => 'Ana Lopez', 'phone_number' => '+5212220000003', 'kinship_type' => KinshipType::Parent->value],
                ['name' => 'Luis Ramos', 'phone_number' => '+5212220000002', 'kinship_type' => KinshipType::Friend->value],
            ],
        ]));

        expect($patient->emergencyContacts()->orderBy('phone_number')->get(['phone_number', 'name'])->map->only(['phone_number', 'name'])->all())->toBe([
            ['phone_number' => '+5212220000002', 'name' => 'Luis Ramos'],
            ['phone_number' => '+5212220000003', 'name' => 'Ana Lopez'],
        ]);
    });

    it('adds, keeps and removes ailments matched by type', function () {
        $patient = PatientFixtures::register();
        $this->actingAs(User::factory()->create());
        $kept = $patient->ailments()->where('ailment_type', AilmentType::Hypertension->value)->first();
        $last = writerLastAuditId();

        app(PatientWriter::class)->update($patient, PatientFixtures::editPayload($patient, [
            'ailments' => [
                ['ailment_type' => AilmentType::Hypertension->value, 'diagnosed_at' => '2018-03-04', 'treatment_notes' => 'Dieta'],
                ['ailment_type' => AilmentType::Epilepsy->value, 'diagnosed_at' => '2021-01-01', 'treatment_notes' => null],
            ],
        ]));

        $audits = writerAuditsSince($patient, $last);

        expect($audits->pluck('event')->sort()->values()->all())->toBe(['created', 'deleted'])
            ->and($audits->firstWhere('event', 'deleted')->old_values)->toMatchArray(['ailment_type' => AilmentType::Diabetes->value])
            ->and($patient->ailments()->pluck('ailment_type')->map->value->sort()->values()->all())->toBe([AilmentType::Hypertension->value, AilmentType::Epilepsy->value])
            ->and($patient->ailments()->where('ailment_type', AilmentType::Hypertension->value)->first()->id)->toBe($kept->id);
    });

    it('removes a one-to-one section with a model delete when the payload no longer carries it', function () {
        $patient = PatientFixtures::register([
            'patient' => ['sex_at_birth' => SexAtBirth::Female->value],
            'gynecological_history' => PatientFixtures::gynecologicalHistory(),
        ]);
        $this->actingAs(User::factory()->create());
        $last = writerLastAuditId();

        app(PatientWriter::class)->update($patient, PatientFixtures::editPayload($patient, [
            'other_ailments' => null,
            'gynecological_history' => null,
        ]));

        $deleted = writerAuditsSince($patient, $last);

        expect($deleted->pluck('event')->unique()->all())->toBe(['deleted'])
            ->and($deleted->pluck('auditable_type')->sort()->values()->all())->toBe(collect([PatientOtherAilment::class, PatientGynecologicalHistory::class])->sort()->values()->all())
            ->and($deleted->every(fn (Audit $audit): bool => $audit->old_values !== [] && $audit->tags === 'patient:'.$patient->id))->toBeTrue()
            ->and($patient->otherAilments()->exists())->toBeFalse()
            ->and($patient->gynecologicalHistory()->exists())->toBeFalse();
    });

    it('creates a one-to-one section that did not exist', function () {
        $patient = PatientFixtures::register(['other_ailments' => null]);
        $this->actingAs(User::factory()->create());

        app(PatientWriter::class)->update($patient, PatientFixtures::editPayload($patient, [
            'other_ailments' => ['surgeries' => null, 'allergies' => 'Polen', 'others' => null],
        ]));

        expect($patient->otherAilments()->first()->allergies)->toBe('Polen');
    });
});

describe('audits of an edit', function () {
    it('writes nothing for a save without any effective change', function () {
        $patient = PatientFixtures::register([
            'patient' => ['sex_at_birth' => SexAtBirth::Female->value],
            'gynecological_history' => PatientFixtures::gynecologicalHistory(),
        ]);
        $this->actingAs(User::factory()->create());
        $before = Audit::query()->count();

        app(PatientWriter::class)->update($patient, PatientFixtures::editPayload($patient));

        expect(Audit::query()->count())->toBe($before);
    });

    it('writes nothing and keeps the old state when a later write of the edit fails', function () {
        $patient = PatientFixtures::register();
        $this->actingAs(User::factory()->create());
        $before = Audit::query()->count();
        Event::listen('eloquent.creating: '.PatientEmergencyContact::class, fn () => throw new RuntimeException('forced failure'));

        expect(fn () => app(PatientWriter::class)->update($patient, PatientFixtures::editPayload($patient, [
            'patient' => [...PatientFixtures::editPayload($patient)['patient'], 'first_name' => 'Cambiado'],
            'emergency_contacts' => [
                ...PatientFixtures::editPayload($patient)['emergency_contacts'],
                ['name' => 'Nuevo', 'phone_number' => '+5212220000077', 'kinship_type' => KinshipType::Other->value],
            ],
        ])))->toThrow(RuntimeException::class, 'forced failure');

        expect($patient->fresh()->first_name)->not->toBe('Cambiado')
            ->and($patient->emergencyContacts()->count())->toBe(2)
            ->and(Audit::query()->count())->toBe($before);
    });

    it('shares one batch among all audits of an edit and gives each operation its own batch', function () {
        $patient = PatientFixtures::register();
        $actor = User::factory()->create();
        $this->actingAs($actor);
        $registration = writerPatientAudits($patient)->pluck('batch_id')->unique();
        $last = writerLastAuditId();

        app(PatientWriter::class)->update($patient, PatientFixtures::editPayload($patient, [
            'patient' => [...PatientFixtures::editPayload($patient)['patient'], 'first_name' => 'Cambiado'],
            'contact_information' => [...PatientFixtures::editPayload($patient)['contact_information'], 'address' => 'Otra calle 1'],
            'ailments' => [['ailment_type' => AilmentType::Diabetes->value, 'diagnosed_at' => '2016-01-01', 'treatment_notes' => null]],
        ]));
        $edit = writerAuditsSince($patient, $last);
        $afterEdit = writerLastAuditId();

        app(PatientDisabling::class)->disable($patient->fresh(), $actor, 'Duplicado');
        $disable = writerAuditsSince($patient, $afterEdit);

        expect($edit->pluck('auditable_type')->unique()->count())->toBeGreaterThanOrEqual(3)
            ->and($edit->pluck('batch_id')->unique())->toHaveCount(1)
            ->and($edit->pluck('user_id')->unique()->all())->toBe([$actor->id])
            ->and($disable->pluck('batch_id')->unique())->toHaveCount(1)
            ->and(collect([$registration->first(), $edit->first()->batch_id, $disable->first()->batch_id])->unique())->toHaveCount(3);
    });
});

describe('editing a disabled patient', function () {
    it('rejects every actor at the service under the row lock and changes nothing', function () {
        $patient = PatientFixtures::registerDisabled();
        $before = Audit::query()->count();

        expect(fn () => app(PatientWriter::class)->update($patient, PatientFixtures::editPayload($patient, [
            'patient' => [...PatientFixtures::editPayload($patient)['patient'], 'first_name' => 'Cambiado'],
        ])))->toThrow(ValidationException::class);

        expect($patient->fresh()->first_name)->not->toBe('Cambiado')
            ->and(Audit::query()->count())->toBe($before);
    });
});
