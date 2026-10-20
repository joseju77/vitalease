<?php

use App\Enums\InventoryMovementType;
use App\Enums\Permission;
use App\Models\Audit;
use App\Models\InventoryMovement;
use App\Models\MedicalConsultation;
use App\Models\Medication;
use App\Models\Patient;
use App\Models\User;
use App\Services\Inventory\TreatmentDispensation;
use App\Services\MedicalConsultations\ConsultationVoiding;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Create a consultation owned by `$owner` that dispensed the given quantities
 * (medication => quantity) through the real treatment sync.
 *
 * @param  list<array{Medication, int}>  $lines
 */
function voidingConsultation(User $owner, array $lines = [], ?Patient $patient = null): MedicalConsultation
{
    $consultation = MedicalConsultation::factory()->withoutRegulation()->create([
        'physician_id' => $owner->id,
        'patient_id' => ($patient ?? Patient::factory()->create())->id,
    ]);

    DB::transaction(fn () => app(TreatmentDispensation::class)->sync(
        $consultation,
        array_map(fn (array $line): array => [
            'medication_uuid' => $line[0]->uuid,
            'quantity_dispensed' => $line[1],
            'dose' => '500 mg',
            'frequency' => 'Cada 8 horas',
            'duration' => '5 días',
        ], $lines),
        $owner,
    ));

    return $consultation;
}

function voidingOwner(Permission ...$extra): User
{
    return User::factory()->withPermissions(Permission::ConsultationsView, Permission::ConsultationsVoid, ...$extra)->create();
}

/**
 * @return Collection<int, Audit>
 */
function voidingAudits(MedicalConsultation $consultation, string $event)
{
    return Audit::query()
        ->where('tags', 'consultation:'.$consultation->id)
        ->where('event', $event)
        ->orderBy('id')
        ->get();
}

describe('voiding a consultation', function () {
    it('returns the dispensed stock, keeps the lines and sets the three void columns together', function () {
        $owner = voidingOwner();
        $medication = Medication::factory()->create(['current_stock' => 10]);
        $consultation = voidingConsultation($owner, [[$medication, 5]]);
        expect($medication->fresh()->current_stock)->toBe(5);

        $this->actingAs($owner)->post(route('consultations.void', $consultation), ['reason' => 'Registro duplicado'])
            ->assertRedirect(route('consultations.show', $consultation));

        $voided = MedicalConsultation::withTrashed()->findOrFail($consultation->id);
        expect($medication->fresh()->current_stock)->toBe(10)
            ->and($voided->treatments()->count())->toBe(1)
            ->and($voided->voided_at)->not->toBeNull()
            ->and($voided->voided_by_id)->toBe($owner->id)
            ->and($voided->void_reason)->toBe('Registro duplicado')
            ->and(InventoryMovement::query()->where('type', InventoryMovementType::DispensationReversal)->where('medication_id', $medication->id)->sole()->quantity)->toBe(5);
    });

    it('requires a reason of at most 500 characters and changes nothing otherwise', function (mixed $reason) {
        $owner = voidingOwner();
        $medication = Medication::factory()->create(['current_stock' => 10]);
        $consultation = voidingConsultation($owner, [[$medication, 5]]);
        $movements = InventoryMovement::query()->count();

        $this->actingAs($owner)->post(route('consultations.void', $consultation), $reason === null ? [] : ['reason' => $reason])
            ->assertSessionHasErrors('reason');

        expect($medication->fresh()->current_stock)->toBe(5)
            ->and($consultation->fresh()->voided_at)->toBeNull()
            ->and($consultation->fresh()->void_reason)->toBeNull()
            ->and(InventoryMovement::query()->count())->toBe($movements);
    })->with(['missing' => [null], 'blank' => [''], 'too long' => [fn () => str_repeat('a', 501)]]);

    it('hides the voided consultation from the default scope but keeps it with trashed', function () {
        $owner = voidingOwner();
        $consultation = voidingConsultation($owner);

        $this->actingAs($owner)->post(route('consultations.void', $consultation), ['reason' => 'Registro duplicado']);

        expect(MedicalConsultation::query()->count())->toBe(0)
            ->and(MedicalConsultation::withTrashed()->count())->toBe(1)
            ->and(MedicalConsultation::onlyTrashed()->sole()->is($consultation))->toBeTrue();
    });

    it('answers 404 to a second void of the same consultation and moves stock only once', function () {
        $owner = voidingOwner();
        $medication = Medication::factory()->create(['current_stock' => 10]);
        $consultation = voidingConsultation($owner, [[$medication, 5]]);

        $this->actingAs($owner)->post(route('consultations.void', $consultation), ['reason' => 'Registro duplicado'])->assertRedirect();
        $this->actingAs($owner)->post(route('consultations.void', $consultation), ['reason' => 'Otra vez'])->assertNotFound();

        expect($medication->fresh()->current_stock)->toBe(10)
            ->and(InventoryMovement::query()->where('type', InventoryMovementType::DispensationReversal)->count())->toBe(1)
            ->and(voidingAudits($consultation, 'voided'))->toHaveCount(1);
    });

    it('rejects a void raced by another request after the row is locked, without moving stock again', function () {
        $owner = voidingOwner();
        $medication = Medication::factory()->create(['current_stock' => 10]);
        $consultation = voidingConsultation($owner, [[$medication, 5]]);
        $staleCopy = MedicalConsultation::query()->findOrFail($consultation->id);
        $service = app(ConsultationVoiding::class);

        $service->void($consultation, $owner, 'Primera anulación');

        expect(fn () => $service->void($staleCopy, $owner, 'Segunda anulación'))->toThrow(ValidationException::class)
            ->and($medication->fresh()->current_stock)->toBe(10)
            ->and(MedicalConsultation::withTrashed()->findOrFail($consultation->id)->void_reason)->toBe('Primera anulación');
    });

    it('never reuses the code of a voided consultation', function () {
        $owner = voidingOwner();
        $first = voidingConsultation($owner);
        $second = voidingConsultation($owner);
        $this->actingAs($owner)->post(route('consultations.void', $second), ['reason' => 'Registro duplicado']);

        $next = voidingConsultation($owner);

        $sequence = fn (MedicalConsultation $consultation): int => (int) substr($consultation->code, -4);
        expect($sequence($next))->toBe($sequence($second) + 1)
            ->and($next->code)->not->toBe($second->code)
            ->and($first->code)->not->toBe($next->code);
    });
});

describe('restoring a consultation', function () {
    it('dispenses the preserved lines again and clears the void columns together', function () {
        $owner = voidingOwner();
        $medication = Medication::factory()->create(['current_stock' => 10]);
        $consultation = voidingConsultation($owner, [[$medication, 5]]);
        $this->actingAs($owner)->post(route('consultations.void', $consultation), ['reason' => 'Registro duplicado']);

        $this->actingAs($owner)->post(route('consultations.restore', $consultation), ['reason' => 'Se anuló por error'])
            ->assertRedirect(route('consultations.show', $consultation));

        $restored = MedicalConsultation::query()->findOrFail($consultation->id);
        expect($medication->fresh()->current_stock)->toBe(5)
            ->and($restored->voided_at)->toBeNull()
            ->and($restored->voided_by_id)->toBeNull()
            ->and($restored->void_reason)->toBeNull()
            ->and($restored->treatments()->count())->toBe(1)
            ->and(MedicalConsultation::query()->count())->toBe(1);
    });

    it('requires a reason', function () {
        $owner = voidingOwner();
        $consultation = voidingConsultation($owner);
        $this->actingAs($owner)->post(route('consultations.void', $consultation), ['reason' => 'Registro duplicado']);

        $this->actingAs($owner)->post(route('consultations.restore', $consultation), [])->assertSessionHasErrors('reason');

        expect(MedicalConsultation::withTrashed()->findOrFail($consultation->id)->trashed())->toBeTrue();
    });

    it('allows restoring a line whose medication is now inactive', function () {
        $owner = voidingOwner();
        $medication = Medication::factory()->create(['current_stock' => 10]);
        $consultation = voidingConsultation($owner, [[$medication, 5]]);
        $this->actingAs($owner)->post(route('consultations.void', $consultation), ['reason' => 'Registro duplicado']);
        $medication->forceFill(['is_active' => false])->save();

        $this->actingAs($owner)->post(route('consultations.restore', $consultation), ['reason' => 'Se anuló por error'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        expect($medication->fresh()->current_stock)->toBe(5)
            ->and(MedicalConsultation::query()->count())->toBe(1);
    });

    it('rejects the whole restore with one error per short line and changes nothing', function () {
        $owner = voidingOwner();
        $enough = Medication::factory()->create(['current_stock' => 10, 'name' => 'Paracetamol']);
        $short = Medication::factory()->create(['current_stock' => 10, 'name' => 'Ibuprofeno']);
        $consultation = voidingConsultation($owner, [[$enough, 4], [$short, 6]]);
        $this->actingAs($owner)->post(route('consultations.void', $consultation), ['reason' => 'Registro duplicado']);
        $short->forceFill(['current_stock' => 2])->save();
        $movements = InventoryMovement::query()->count();
        $audits = Audit::query()->count();

        $response = $this->actingAs($owner)->post(route('consultations.restore', $consultation), ['reason' => 'Se anuló por error']);

        $response->assertSessionHasErrors(['treatment.1'])->assertSessionDoesntHaveErrors(['treatment.0']);
        expect(session('errors')->first('treatment.1'))->toContain('Ibuprofeno')->toContain('Disponible: 2')
            ->and($enough->fresh()->current_stock)->toBe(10)
            ->and($short->fresh()->current_stock)->toBe(2)
            ->and(MedicalConsultation::withTrashed()->findOrFail($consultation->id)->void_reason)->toBe('Registro duplicado')
            ->and(InventoryMovement::query()->count())->toBe($movements)
            ->and(Audit::query()->count())->toBe($audits)
            ->and(voidingAudits($consultation, 'restored'))->toHaveCount(0);
    });

    it('rejects restoring a consultation that is not voided with a validation error', function () {
        $owner = voidingOwner();
        $medication = Medication::factory()->create(['current_stock' => 10]);
        $consultation = voidingConsultation($owner, [[$medication, 5]]);

        expect(fn () => app(ConsultationVoiding::class)->restore($consultation, $owner, 'Sin necesidad'))
            ->toThrow(ValidationException::class);
        expect($medication->fresh()->current_stock)->toBe(5);
    });

    it('rejects a restore raced by another request after the row is locked, without dispensing again', function () {
        $owner = voidingOwner();
        $medication = Medication::factory()->create(['current_stock' => 10]);
        $consultation = voidingConsultation($owner, [[$medication, 5]]);
        $service = app(ConsultationVoiding::class);
        $service->void($consultation, $owner, 'Registro duplicado');
        $staleCopy = MedicalConsultation::withTrashed()->findOrFail($consultation->id);

        $service->restore($consultation, $owner, 'Primera restauración');

        expect(fn () => $service->restore($staleCopy, $owner, 'Segunda restauración'))->toThrow(ValidationException::class)
            ->and($medication->fresh()->current_stock)->toBe(5);
    });

    it('keeps only the current void in the columns and every cycle in the audits', function () {
        $owner = voidingOwner();
        $consultation = voidingConsultation($owner);
        $service = app(ConsultationVoiding::class);

        $service->void($consultation, $owner, 'Primera anulación');
        $service->restore($consultation, $owner, 'Restauración');
        $service->void($consultation, $owner, 'Segunda anulación');

        expect(MedicalConsultation::withTrashed()->findOrFail($consultation->id)->void_reason)->toBe('Segunda anulación')
            ->and(voidingAudits($consultation, 'voided')->pluck('new_values.reason')->all())->toBe(['Primera anulación', 'Segunda anulación'])
            ->and(voidingAudits($consultation, 'restored')->pluck('new_values.reason')->all())->toBe(['Restauración']);
    });

    it('lets void-any restore another physician\'s consultation and view it', function () {
        $physician = voidingOwner();
        $coordinator = User::factory()->withPermissions(Permission::ConsultationsView, Permission::ConsultationsVoidAny)->create();
        $consultation = voidingConsultation($physician);
        $this->actingAs($coordinator)->post(route('consultations.void', $consultation), ['reason' => 'Error de captura']);

        $this->actingAs($coordinator)->get(route('consultations.show', $consultation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('consultation.voided_at', fn ($value) => $value !== null)
                ->where('consultation.voided_by.name', $coordinator->name)
                ->where('consultation.void_reason', 'Error de captura')
                ->where('consultation.can.restore', true)
                ->where('consultation.can.void', false)
                ->where('consultation.can.update', false));
        $this->actingAs($coordinator)->post(route('consultations.restore', $consultation), ['reason' => 'Era válida'])->assertRedirect();

        expect(MedicalConsultation::query()->count())->toBe(1);
    });

    it('does not offer a super-admin void or edit on a voided consultation, only restore', function () {
        $admin = User::factory()->superAdmin()->create();
        $consultation = MedicalConsultation::factory()->withoutRegulation()->voided()->create();

        $this->actingAs($admin)->get(route('consultations.show', $consultation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('consultation.can.update', false)
                ->where('consultation.can.void', false)
                ->where('consultation.can.restore', true)
                ->where('consultation.can.audit', true));
    });
});

describe('voiding and restoring audit', function () {
    it('writes exactly one custom voided audit with the actor, the reason and no attribute snapshot', function () {
        $owner = voidingOwner();
        $consultation = voidingConsultation($owner);

        $this->actingAs($owner)->post(route('consultations.void', $consultation), ['reason' => 'duplicate']);

        $audit = voidingAudits($consultation, 'voided')->sole();
        expect($audit->auditable_type)->toBe(MedicalConsultation::class)
            ->and($audit->auditable_id)->toBe($consultation->id)
            ->and($audit->user_id)->toBe($owner->id)
            ->and($audit->tags)->toBe('consultation:'.$consultation->id)
            ->and($audit->old_values)->toBe([])
            ->and($audit->new_values)->toBe(['reason' => 'duplicate'])
            ->and($audit->batch_id)->not->toBeNull()
            ->and(Audit::query()->where('batch_id', $audit->batch_id)->count())->toBe(1);
        expect(voidingAudits($consultation, 'deleted'))->toHaveCount(0)
            ->and(voidingAudits($consultation, 'updated'))->toHaveCount(0);
    });

    it('writes exactly one custom restored audit with the actor and the reason', function () {
        $owner = voidingOwner();
        $consultation = voidingConsultation($owner);
        $this->actingAs($owner)->post(route('consultations.void', $consultation), ['reason' => 'duplicate']);

        $this->actingAs($owner)->post(route('consultations.restore', $consultation), ['reason' => 'valid after all']);

        $audit = voidingAudits($consultation, 'restored')->sole();
        expect($audit->user_id)->toBe($owner->id)
            ->and($audit->new_values)->toBe(['reason' => 'valid after all'])
            ->and($audit->old_values)->toBe([])
            ->and($audit->batch_id)->not->toBe(voidingAudits($consultation, 'voided')->sole()->batch_id)
            ->and(voidingAudits($consultation, 'updated'))->toHaveCount(0);
    });
});
