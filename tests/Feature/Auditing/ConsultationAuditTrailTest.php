<?php

use App\Enums\MedicalClassification;
use App\Enums\MedicalState;
use App\Enums\Permission;
use App\Enums\TransferType;
use App\Models\Audit;
use App\Models\MedicalConsultation;
use App\Models\MedicalConsultationTreatment;
use App\Models\MedicalRegulation;
use App\Models\Medication;
use App\Models\Patient;
use App\Models\PhysicalExamination;
use App\Models\User;
use App\Models\VitalSigns;
use App\Services\Inventory\InsufficientStockException;
use App\Services\MedicalConsultations\ConsultationWriter;
use Illuminate\Support\Collection;

/**
 * Build a validated-style consultation payload as `ConsultationWriter` expects it.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function auditedConsultationData(array $overrides = []): array
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

/**
 * @return array<string, mixed>
 */
function auditedRegulationData(): array
{
    return [
        'transfer_type' => TransferType::OwnResources->value,
        'regulated_at' => '2026-10-18 10:00:00',
        'ambulance_registration' => 'AMB-1',
        'regulation_number' => 'REG-1',
        'clinic_id' => 'CL-1',
        'receiver_physician' => 'Dra. Pérez',
    ];
}

/**
 * @return array<string, mixed>
 */
function auditedTreatmentLine(Medication $medication, int $quantity = 1): array
{
    return [
        'medication_uuid' => $medication->uuid,
        'quantity_dispensed' => $quantity,
        'dose' => '500 mg',
        'frequency' => 'Cada 8 horas',
        'duration' => '5 días',
    ];
}

function auditedConsultation(User $actor, array $data = []): MedicalConsultation
{
    test()->actingAs($actor);

    return app(ConsultationWriter::class)->create(Patient::factory()->create(), auditedConsultationData($data), $actor);
}

/**
 * @return Collection<int, Audit>
 */
function consultationAudits(MedicalConsultation $consultation): Collection
{
    return Audit::query()->where('tags', 'consultation:'.$consultation->id)->orderBy('id')->get();
}

it('tags every audited section of a consultation with its consultation tag and the acting user', function () {
    $actor = User::factory()->create();
    $medication = Medication::factory()->create(['current_stock' => 10]);

    $consultation = auditedConsultation($actor, [
        'regulation' => auditedRegulationData(),
        'treatment' => [auditedTreatmentLine($medication, 2)],
    ]);

    $audits = consultationAudits($consultation);

    expect($audits->pluck('auditable_type')->unique()->sort()->values()->all())->toBe(collect([
        MedicalConsultation::class,
        VitalSigns::class,
        PhysicalExamination::class,
        MedicalRegulation::class,
        MedicalConsultationTreatment::class,
    ])->sort()->values()->all())
        ->and($audits->pluck('event')->unique()->all())->toBe(['created'])
        ->and($audits->pluck('user_id')->unique()->all())->toBe([$actor->id])
        ->and($audits->pluck('user_type')->unique()->all())->toBe([User::class]);
});

it('audits a section update with the acting user and the changed values', function () {
    $actor = User::factory()->create();
    $consultation = auditedConsultation($actor);

    app(ConsultationWriter::class)->update($consultation, auditedConsultationData(['vital_signs' => ['heart_rate' => 99]]), $actor);

    $audit = consultationAudits($consultation)
        ->where('auditable_type', VitalSigns::class)
        ->where('event', 'updated')
        ->sole();

    expect($audit->tags)->toBe('consultation:'.$consultation->id)
        ->and($audit->user_id)->toBe($actor->id)
        ->and($audit->old_values)->toBe(['heart_rate' => 72])
        ->and($audit->new_values)->toBe(['heart_rate' => 99]);
});

it('audits the removal of a regulation with its old values', function () {
    $actor = User::factory()->create();
    $consultation = auditedConsultation($actor, ['regulation' => auditedRegulationData()]);

    app(ConsultationWriter::class)->update($consultation, auditedConsultationData(['regulation' => null]), $actor);

    $audit = consultationAudits($consultation)
        ->where('auditable_type', MedicalRegulation::class)
        ->where('event', 'deleted')
        ->sole();

    expect($audit->old_values)->toMatchArray(['ambulance_registration' => 'AMB-1', 'regulation_number' => 'REG-1'])
        ->and($audit->new_values)->toBe([])
        ->and(MedicalRegulation::query()->count())->toBe(0);
});

it('audits the removal of a treatment line and deletes its row', function () {
    $actor = User::factory()->create();
    $kept = Medication::factory()->create(['current_stock' => 10]);
    $removed = Medication::factory()->create(['current_stock' => 10]);
    $consultation = auditedConsultation($actor, [
        'treatment' => [auditedTreatmentLine($kept, 1), auditedTreatmentLine($removed, 2)],
    ]);

    app(ConsultationWriter::class)->update($consultation, auditedConsultationData(['treatment' => [auditedTreatmentLine($kept, 1)]]), $actor);

    $audit = consultationAudits($consultation)
        ->where('auditable_type', MedicalConsultationTreatment::class)
        ->where('event', 'deleted')
        ->sole();

    expect($audit->old_values)->toMatchArray(['quantity_dispensed' => 2, 'medication' => $removed->auditLabel()])
        ->and($consultation->treatments()->count())->toBe(1);
});

it('shares one batch id across every audit of a single save', function () {
    $actor = User::factory()->create();
    $medication = Medication::factory()->create(['current_stock' => 10]);
    $consultation = auditedConsultation($actor);
    $creationAuditIds = consultationAudits($consultation)->pluck('id');

    app(ConsultationWriter::class)->update($consultation, auditedConsultationData([
        'consultation' => ['diagnosis' => 'Colitis'],
        'vital_signs' => ['heart_rate' => 88],
        'treatment' => [auditedTreatmentLine($medication, 3)],
    ]), $actor);

    $updateAudits = consultationAudits($consultation)->reject(fn (Audit $audit) => $creationAuditIds->contains($audit->id));

    expect($updateAudits->pluck('auditable_type')->unique())->toHaveCount(3)
        ->and($updateAudits->pluck('batch_id')->unique())->toHaveCount(1)
        ->and($updateAudits->first()->batch_id)->not->toBeNull();
});

it('uses different batch ids for separate operations', function () {
    $actor = User::factory()->create();
    $consultation = auditedConsultation($actor);

    app(ConsultationWriter::class)->update($consultation, auditedConsultationData(['vital_signs' => ['heart_rate' => 80]]), $actor);
    app(ConsultationWriter::class)->update($consultation, auditedConsultationData(['vital_signs' => ['heart_rate' => 85]]), $actor);

    $updates = consultationAudits($consultation)->where('event', 'updated');
    $creationBatch = consultationAudits($consultation)->where('event', 'created')->pluck('batch_id')->unique();

    expect($updates)->toHaveCount(2)
        ->and($updates->pluck('batch_id')->unique())->toHaveCount(2)
        ->and($creationBatch)->toHaveCount(1)
        ->and($updates->pluck('batch_id')->contains($creationBatch->first()))->toBeFalse();
});

it('leaves the batch id null for writes outside an audit batch', function () {
    $consultation = MedicalConsultation::factory()->create();

    expect(consultationAudits($consultation)->sole()->batch_id)->toBeNull();
});

it('records the authenticated web user as actor and one batch per request over HTTP', function () {
    $physician = User::factory()->withPermissions(Permission::ConsultationsCreate, Permission::ConsultationsUpdate)->create();
    $patient = Patient::factory()->create();

    $this->actingAs($physician)->post(
        route('consultations.store'),
        ['patient_uuid' => $patient->uuid, ...auditedConsultationData()],
    )->assertSessionHasNoErrors();

    $consultation = MedicalConsultation::query()->sole();

    $this->actingAs($physician)->put(
        route('consultations.update', $consultation),
        auditedConsultationData(['vital_signs' => ['heart_rate' => 101]]),
    )->assertSessionHasNoErrors();

    $audits = consultationAudits($consultation);

    expect($audits->pluck('user_id')->unique()->all())->toBe([$physician->id])
        ->and($audits->pluck('batch_id')->unique())->toHaveCount(2)
        ->and($audits->pluck('batch_id')->contains(null))->toBeFalse();
});

it('does not write audits for a save rolled back by insufficient stock', function () {
    $actor = User::factory()->create();
    $medication = Medication::factory()->create(['current_stock' => 1]);
    $consultation = auditedConsultation($actor);
    $before = Audit::query()->count();

    expect(fn () => app(ConsultationWriter::class)->update($consultation, auditedConsultationData([
        'vital_signs' => ['heart_rate' => 110],
        'treatment' => [auditedTreatmentLine($medication, 5)],
    ]), $actor))->toThrow(InsufficientStockException::class);

    expect(Audit::query()->count())->toBe($before)
        ->and($consultation->vitalSigns()->first()->heart_rate)->toBe(72);
});

it('cannot update or delete an audit row', function () {
    $consultation = MedicalConsultation::factory()->create();
    $audit = consultationAudits($consultation)->sole();

    expect(fn () => $audit->update(['event' => 'tampered']))->toThrow(LogicException::class)
        ->and(fn () => $audit->delete())->toThrow(LogicException::class);

    expect(Audit::query()->find($audit->id))->not->toBeNull()
        ->and($audit->fresh()->event)->toBe('created');
});

it('keeps timestamps and internal ids out of the audited values', function () {
    $actor = User::factory()->create();
    $medication = Medication::factory()->create(['current_stock' => 10]);
    $consultation = auditedConsultation($actor, ['treatment' => [auditedTreatmentLine($medication)]]);

    app(ConsultationWriter::class)->update($consultation, auditedConsultationData([
        'consultation' => ['diagnosis' => 'Otro'],
        'treatment' => [auditedTreatmentLine($medication, 2)],
    ]), $actor);

    $keys = consultationAudits($consultation)
        ->flatMap(fn (Audit $audit) => [...array_keys($audit->old_values), ...array_keys($audit->new_values)])
        ->unique();

    expect($keys->intersect([
        'created_at', 'updated_at', 'id', 'uuid', 'patient_id', 'physician_id', 'medical_consultation_id', 'medication_id',
        'voided_at', 'voided_by_id', 'void_reason',
    ]))->toBeEmpty();
});

it('adds the medication label to treatment audits instead of the medication id', function () {
    $actor = User::factory()->create();
    $medication = Medication::factory()->create(['current_stock' => 10]);
    $label = $medication->auditLabel();
    $consultation = auditedConsultation($actor, ['treatment' => [auditedTreatmentLine($medication, 1)]]);

    app(ConsultationWriter::class)->update($consultation, auditedConsultationData(['treatment' => [auditedTreatmentLine($medication, 2)]]), $actor);
    app(ConsultationWriter::class)->update($consultation, auditedConsultationData(['treatment' => []]), $actor);

    $audits = consultationAudits($consultation)->where('auditable_type', MedicalConsultationTreatment::class)->values();

    expect($audits->pluck('event')->all())->toBe(['created', 'updated', 'deleted'])
        ->and($audits[0]->new_values['medication'])->toBe($label)
        ->and($audits[0]->old_values)->toBe([])
        ->and($audits[1]->old_values['medication'])->toBe($label)
        ->and($audits[1]->new_values['medication'])->toBe($label)
        ->and($audits[2]->old_values['medication'])->toBe($label)
        ->and($audits[2]->new_values)->toBe([]);
});

it('returns the whole consultation history with one tag-filtered query', function () {
    $actor = User::factory()->create();
    $other = auditedConsultation($actor);
    $consultation = auditedConsultation($actor, ['regulation' => auditedRegulationData()]);

    $audits = consultationAudits($consultation);

    expect($audits->pluck('auditable_type')->unique())->toHaveCount(4)
        ->and($audits->pluck('tags')->unique()->all())->toBe(['consultation:'.$consultation->id])
        ->and(consultationAudits($other)->pluck('tags')->unique()->all())->toBe(['consultation:'.$other->id]);
});
