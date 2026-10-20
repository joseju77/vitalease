<?php

use App\Enums\MedicalClassification;
use App\Enums\MedicalState;
use App\Enums\Permission;
use App\Models\Audit;
use App\Models\MedicalConsultation;
use App\Models\Medication;
use App\Models\Patient;
use App\Models\User;
use App\Services\MedicalConsultations\ConsultationHistory;
use App\Services\MedicalConsultations\ConsultationVoiding;
use App\Services\MedicalConsultations\ConsultationWriter;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function historyConsultationData(array $overrides = []): array
{
    return array_replace_recursive([
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
    ], $overrides);
}

function historyAuditor(): User
{
    return User::factory()->withPermissions(Permission::ConsultationsView, Permission::ConsultationsAudit)->create();
}

describe('consultation change history', function () {
    it('groups every save, void and restore into one entry each, newest first', function () {
        $actor = User::factory()->create(['name' => 'Dra. Ruiz']);
        $medication = Medication::factory()->create(['name' => 'Paracetamol', 'presentation' => 'Tableta', 'concentration' => '500 mg', 'current_stock' => 20]);
        $this->actingAs($actor);
        $writer = app(ConsultationWriter::class);
        $consultation = $writer->create(Patient::factory()->create(), historyConsultationData(), $actor);
        $writer->update($consultation, historyConsultationData([
            'prognosis' => MedicalState::Serious->value,
            'vital_signs' => ['heart_rate' => 99],
            'treatment' => [['medication_uuid' => $medication->uuid, 'quantity_dispensed' => 2, 'dose' => '500 mg', 'frequency' => 'Cada 8 horas', 'duration' => '5 días']],
        ]), $actor);
        $voiding = app(ConsultationVoiding::class);
        $voiding->void($consultation, $actor, 'Registro duplicado');
        $voiding->restore($consultation, $actor, 'Se anuló por error');

        $response = $this->actingAs(historyAuditor())->getJson(route('consultations.history', $consultation));

        $response->assertOk()->assertJsonCount(4, 'data');
        $groups = collect($response->json('data'));
        expect($groups->pluck('event')->all())->toBe(['restored', 'voided', 'updated', 'created'])
            ->and($groups->pluck('actor.name')->unique()->all())->toBe(['Dra. Ruiz'])
            ->and($groups[0]['reason'])->toBe('Se anuló por error')
            ->and($groups[1]['reason'])->toBe('Registro duplicado')
            ->and($groups[2]['reason'])->toBeNull()
            ->and($groups[0]['changes'])->toBe([])
            ->and($response->json('next_cursor'))->toBeNull();
    });

    it('labels sections, fields, enum values and the medication in Spanish', function () {
        $actor = User::factory()->create();
        $medication = Medication::factory()->create(['name' => 'Paracetamol', 'presentation' => 'Tableta', 'concentration' => '500 mg', 'current_stock' => 20]);
        $this->actingAs($actor);
        $writer = app(ConsultationWriter::class);
        $consultation = $writer->create(Patient::factory()->create(), historyConsultationData(), $actor);
        $writer->update($consultation, historyConsultationData([
            'prognosis' => MedicalState::Serious->value,
            'vital_signs' => ['heart_rate' => 99],
            'treatment' => [['medication_uuid' => $medication->uuid, 'quantity_dispensed' => 2, 'dose' => '500 mg', 'frequency' => 'Cada 8 horas', 'duration' => '5 días']],
        ]), $actor);

        $groups = collect($this->actingAs(historyAuditor())->getJson(route('consultations.history', $consultation))->json('data'));
        $changes = collect($groups->firstWhere('event', 'updated')['changes']);

        $prognosis = $changes->firstWhere('field', 'pronóstico');
        expect($prognosis)->toMatchArray(['section' => 'consulta', 'old' => 'Bueno', 'new' => 'Grave', 'item' => null]);

        $heartRate = $changes->firstWhere('field', 'frecuencia cardiaca');
        expect($heartRate)->toMatchArray(['section' => 'signos vitales', 'old' => 72, 'new' => 99]);

        $quantity = $changes->first(fn (array $change): bool => $change['section'] === 'tratamiento' && $change['field'] === 'cantidad dispensada');
        expect($quantity)->toMatchArray(['item' => 'Paracetamol Tableta 500 mg', 'old' => null, 'new' => 2])
            ->and($changes->pluck('field')->all())->not->toContain('medication_id', 'medication');

        $created = collect($groups->firstWhere('event', 'created')['changes']);
        expect($created->pluck('section')->unique()->sort()->values()->all())->toBe(['consulta', 'exploración física', 'signos vitales'])
            ->and($created->firstWhere('field', 'clasificación médica'))->toMatchArray(['new' => 'Traumatología']);
    });

    it('treats a created consultation batch as the creation and never splits a group', function () {
        $actor = User::factory()->create();
        $this->actingAs($actor);
        $consultation = app(ConsultationWriter::class)->create(Patient::factory()->create(), historyConsultationData(), $actor);

        $groups = collect($this->actingAs(historyAuditor())->getJson(route('consultations.history', $consultation))->json('data'));

        expect($groups)->toHaveCount(1)
            ->and($groups[0]['event'])->toBe('created');
    });

    it('returns an empty list, not an error, for a consultation without audits', function () {
        $consultation = MedicalConsultation::factory()->withoutRegulation()->create();
        // Seeded consultations written outside a curated audited step have no audits at all.
        Audit::query()->where('tags', 'consultation:'.$consultation->id)->delete();

        $this->actingAs(historyAuditor())->getJson(route('consultations.history', $consultation))
            ->assertOk()
            ->assertExactJson(['data' => [], 'next_cursor' => null]);
    });

    it('paginates by group with a cursor and no duplicates', function () {
        $actor = User::factory()->create();
        $this->actingAs($actor);
        $writer = app(ConsultationWriter::class);
        $consultation = $writer->create(Patient::factory()->create(), historyConsultationData(), $actor);
        $total = ConsultationHistory::PAGE_SIZE + 5;

        foreach (range(1, $total - 1) as $heartRate) {
            $writer->update($consultation, historyConsultationData(['vital_signs' => ['heart_rate' => 100 + $heartRate]]), $actor);
        }

        $auditor = historyAuditor();
        $first = $this->actingAs($auditor)->getJson(route('consultations.history', $consultation))->json();
        expect($first['data'])->toHaveCount(ConsultationHistory::PAGE_SIZE)
            ->and($first['next_cursor'])->not->toBeNull();

        $second = $this->actingAs($auditor)->getJson(route('consultations.history', [$consultation, 'cursor' => $first['next_cursor']]))->json();
        expect($second['data'])->toHaveCount(5)
            ->and($second['next_cursor'])->toBeNull();

        $ids = collect([...$first['data'], ...$second['data']])->pluck('id');
        expect($ids->unique()->count())->toBe($total)
            ->and(collect($second['data'])->last()['event'])->toBe('created');
    });

    it('validates the cursor', function () {
        $consultation = MedicalConsultation::factory()->withoutRegulation()->create();

        $this->actingAs(historyAuditor())->getJson(route('consultations.history', [$consultation, 'cursor' => 'abc']))
            ->assertUnprocessable();
    });

    it('serves the history of a voided consultation', function () {
        $actor = User::factory()->create();
        $this->actingAs($actor);
        $consultation = app(ConsultationWriter::class)->create(Patient::factory()->create(), historyConsultationData(), $actor);
        app(ConsultationVoiding::class)->void($consultation, $actor, 'Registro duplicado');

        $this->actingAs(historyAuditor())->getJson(route('consultations.history', $consultation))
            ->assertOk()
            ->assertJsonPath('data.0.event', 'voided');
    });

    it('denies the history to a user with view but without audit', function () {
        $consultation = MedicalConsultation::factory()->withoutRegulation()->create();
        $viewer = User::factory()->withPermissions(Permission::ConsultationsView)->create();

        $this->actingAs($viewer)->getJson(route('consultations.history', $consultation))->assertForbidden();
    });
});
