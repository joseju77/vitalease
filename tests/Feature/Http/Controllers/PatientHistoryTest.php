<?php

use App\Enums\KinshipType;
use App\Enums\SexAtBirth;
use App\Models\Audit;
use App\Models\FamilyMedicalUnit;
use App\Models\Patient;
use App\Models\User;
use App\Services\Patients\PatientDisabling;
use App\Services\Patients\PatientHistory;
use App\Services\Patients\PatientWriter;
use Illuminate\Support\Collection;
use Tests\Support\PatientFixtures;

/**
 * @return Collection<int, array<string, mixed>>
 */
function historyGroups(Patient $patient, ?int $cursor = null): Collection
{
    $url = route('patients.history', $patient).($cursor !== null ? '?cursor='.$cursor : '');

    return collect(test()->actingAs(PatientFixtures::actor('audit'))->getJson($url)->assertOk()->json('data'));
}

function historyEdit(Patient $patient, array $replace): void
{
    app(PatientWriter::class)->update($patient, PatientFixtures::editPayload($patient, $replace));
}

describe('patient change history', function () {
    it('returns one group per operation, newest first, with the actor and reason', function () {
        $patient = PatientFixtures::register();
        $actor = User::factory()->create(['name' => 'Dra. Ruiz']);
        $this->actingAs($actor);
        $edit = PatientFixtures::editPayload($patient);
        historyEdit($patient, [
            'patient' => [...$edit['patient'], 'first_name' => 'Cambiado'],
            'contact_information' => [...$edit['contact_information'], 'address' => 'Otra calle 1'],
            'ailments' => [['ailment_type' => 1, 'diagnosed_at' => '2016-01-01', 'treatment_notes' => null]],
        ]);
        app(PatientDisabling::class)->disable($patient->fresh(), $actor, 'Registro duplicado');
        app(PatientDisabling::class)->enable($patient->fresh(), $actor, 'Se deshabilito por error');

        $response = $this->actingAs(PatientFixtures::actor('audit'))->getJson(route('patients.history', $patient));
        $groups = collect($response->json('data'));

        expect($groups->pluck('event')->all())->toBe(['enabled', 'disabled', 'updated', 'created'])
            ->and($groups->pluck('actor.name')->all())->toBe(['Dra. Ruiz', 'Dra. Ruiz', 'Dra. Ruiz', 'Autorregistro'])
            ->and($groups[0]['reason'])->toBe('Se deshabilito por error')
            ->and($groups[1]['reason'])->toBe('Registro duplicado')
            ->and($groups[2]['reason'])->toBeNull()
            ->and($groups[0]['changes'])->toBe([])
            ->and(collect($groups[2]['changes'])->pluck('section')->unique()->sort()->values()->all())
            ->toBe(['contacto', 'datos generales', 'padecimientos'])
            ->and($response->json('next_cursor'))->toBeNull();
    });

    it('presents an anonymous registration as Autorregistro', function () {
        $patient = PatientFixtures::register();

        $created = historyGroups($patient)->first();

        expect($created['event'])->toBe('created')
            ->and($created['actor'])->toBe(['name' => 'Autorregistro']);
    });

    it('labels sections, fields, catalogs and enum values in Spanish, never with raw ids', function () {
        $oldUnit = FamilyMedicalUnit::factory()->create(['name' => 'UMF 1']);
        $newUnit = FamilyMedicalUnit::factory()->create(['name' => 'UMF 2']);
        $patient = PatientFixtures::register(['patient' => ['family_medical_unit_id' => $oldUnit->id, 'other_family_medical_unit' => null]]);
        $this->actingAs(User::factory()->create());
        $edit = PatientFixtures::editPayload($patient);
        historyEdit($patient, [
            'patient' => [...$edit['patient'], 'family_medical_unit_id' => $newUnit->id],
            'emergency_contacts' => [
                ['name' => 'Ana Lopez', 'phone_number' => '+5212220000002', 'kinship_type' => KinshipType::Spouse->value],
                $edit['emergency_contacts'][1],
            ],
        ]);

        $changes = collect(historyGroups($patient)->firstWhere('event', 'updated')['changes']);

        expect($changes->firstWhere('field', 'unidad médica familiar'))->toMatchArray(['section' => 'datos generales', 'old' => 'UMF 1', 'new' => 'UMF 2'])
            ->and($changes->firstWhere('field', 'parentesco'))->toMatchArray(['section' => 'contactos de emergencia', 'item' => 'Ana Lopez', 'old' => 'Padre/Madre', 'new' => 'Cónyuge'])
            ->and($changes->pluck('field')->all())->not->toContain('family_medical_unit_id', 'family_medical_unit', 'kinship_type', 'contact');
    });

    it('shows a changed emergency contact phone as one removed plus one added contact', function () {
        $patient = PatientFixtures::register();
        $this->actingAs(User::factory()->create());
        $edit = PatientFixtures::editPayload($patient);
        historyEdit($patient, [
            'emergency_contacts' => [
                ['name' => 'Ana Lopez', 'phone_number' => '+5212229999999', 'kinship_type' => KinshipType::Parent->value],
                $edit['emergency_contacts'][1],
            ],
        ]);

        $phones = collect(historyGroups($patient)->firstWhere('event', 'updated')['changes'])
            ->where('section', 'contactos de emergencia')
            ->where('field', 'teléfono del contacto de emergencia')
            ->values();

        expect($phones)->toHaveCount(2)
            ->and($phones->pluck('old')->all())->toBe(['+5212220000002', null])
            ->and($phones->pluck('new')->all())->toBe([null, '+5212229999999']);
    });

    it('never lists an empty-to-empty row in the creation group', function () {
        $patient = PatientFixtures::register([
            'patient' => ['sex_at_birth' => SexAtBirth::Female->value, 'second_last_name' => null],
            'contact_information' => ['institutional_email' => null],
            'gynecological_history' => PatientFixtures::gynecologicalHistory(),
        ]);

        $changes = collect(historyGroups($patient)->firstWhere('event', 'created')['changes']);

        expect($changes)->not->toBeEmpty()
            ->and($changes->filter(fn (array $change): bool => $change['old'] === null && $change['new'] === null))->toBeEmpty()
            ->and($changes->pluck('field')->all())->not->toContain('inscripción', 'unidad médica familiar');
    });

    it('pages through groups with a cursor without splitting or repeating any group', function () {
        $patient = PatientFixtures::register();
        $this->actingAs(User::factory()->create());

        foreach (range(1, PatientHistory::PAGE_SIZE + 2) as $number) {
            $edit = PatientFixtures::editPayload($patient);
            historyEdit($patient, ['contact_information' => [...$edit['contact_information'], 'address' => "Calle {$number}"]]);
        }

        $audit = PatientFixtures::actor('audit');
        $first = $this->actingAs($audit)->getJson(route('patients.history', $patient));
        $second = $this->actingAs($audit)->getJson(route('patients.history', ['patient' => $patient, 'cursor' => $first->json('next_cursor')]));

        $ids = collect([...$first->json('data'), ...$second->json('data')])->pluck('id');

        expect($first->json('data'))->toHaveCount(PatientHistory::PAGE_SIZE)
            ->and($first->json('next_cursor'))->not->toBeNull()
            ->and($second->json('data'))->toHaveCount(3)
            ->and($second->json('next_cursor'))->toBeNull()
            ->and($ids->unique())->toHaveCount(PatientHistory::PAGE_SIZE + 3)
            ->and(collect($second->json('data'))->last()['event'])->toBe('created');
    });

    it('returns an empty list, not an error, for a patient without audits', function () {
        $patient = Patient::withoutAuditing(fn (): Patient => Patient::factory()->create());

        expect(Audit::query()->where('tags', 'patient:'.$patient->id)->count())->toBe(0);

        $response = $this->actingAs(PatientFixtures::actor('audit'))->getJson(route('patients.history', $patient));

        $response->assertOk()->assertExactJson(['data' => [], 'next_cursor' => null]);
    });

    it('answers 403 for a user with patients.view but not patients.audit', function () {
        $patient = PatientFixtures::register();

        $this->actingAs(PatientFixtures::actor('view'))->getJson(route('patients.history', $patient))->assertForbidden();
    });
});
