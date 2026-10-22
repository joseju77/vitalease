<?php

use App\Models\Audit;
use App\Models\InventoryMovement;
use App\Models\MedicalConsultation;
use App\Models\Medication;
use App\Models\Patient;
use App\Models\User;
use App\Services\Patients\PatientDisabling;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use OwenIt\Auditing\Events\AuditCustom;
use Tests\Support\PatientFixtures;

/**
 * @return Collection<int, Audit>
 */
function disablingAudits(Patient $patient, string $auditableType = Patient::class): Collection
{
    return Audit::query()->where('tags', 'patient:'.$patient->id)->where('auditable_type', $auditableType)->orderBy('id')->get();
}

describe('disabling', function () {
    it('stores the time, the user and the reason together and leaves consultations, stock and children untouched', function () {
        $patient = PatientFixtures::register();
        $medication = Medication::factory()->create(['current_stock' => 12]);
        $consultations = MedicalConsultation::factory()->withoutRegulation()->count(2)->create(['patient_id' => $patient->id]);
        $movements = InventoryMovement::query()->count();
        $actor = PatientFixtures::actor('disable');

        $this->actingAs($actor)->post(route('patients.disable', $patient), ['reason' => 'duplicado'])
            ->assertRedirect(route('patients.show', $patient));

        $patient->refresh();

        expect($patient->disabled_at)->not->toBeNull()
            ->and($patient->disabled_by_id)->toBe($actor->id)
            ->and($patient->disable_reason)->toBe('duplicado')
            ->and($patient->is_disabled)->toBeTrue()
            ->and(MedicalConsultation::query()->where('patient_id', $patient->id)->count())->toBe(2)
            ->and($consultations->every(fn (MedicalConsultation $c): bool => $c->fresh()->patient_id === $patient->id && ! $c->fresh()->trashed()))->toBeTrue()
            ->and($medication->fresh()->current_stock)->toBe(12)
            ->and(InventoryMovement::query()->count())->toBe($movements)
            ->and($patient->emergencyContacts()->count())->toBe(2)
            ->and($patient->ailments()->count())->toBe(2)
            ->and($patient->contactInformation()->exists())->toBeTrue()
            ->and($patient->otherAilments()->exists())->toBeTrue();
    });

    it('keeps the consultations of a disabled patient readable with the patient relation resolving', function () {
        $patient = PatientFixtures::registerDisabled();
        $consultations = MedicalConsultation::factory()->withoutRegulation()->count(2)->create(['patient_id' => $patient->id]);
        $viewer = User::factory()->superAdmin()->create();

        foreach ($consultations as $consultation) {
            $this->actingAs($viewer)->get(route('consultations.show', $consultation))->assertOk();
            expect($consultation->fresh()->patient)->not->toBeNull();
        }

        $this->actingAs($viewer)->get(route('patients.show', $patient))->assertOk()
            ->assertInertia(fn ($page) => $page->has('consultations', 2));
    });

    it('requires a reason of at most 500 characters and changes nothing otherwise', function (mixed $reason) {
        $patient = PatientFixtures::register();
        $audits = Audit::query()->count();

        $this->actingAs(PatientFixtures::actor('disable'))
            ->post(route('patients.disable', $patient), $reason === 'omitted' ? [] : ['reason' => $reason])
            ->assertSessionHasErrors('reason');

        expect($patient->fresh()->disabled_at)->toBeNull()
            ->and($patient->fresh()->disable_reason)->toBeNull()
            ->and(Audit::query()->count())->toBe($audits);
    })->with([
        'missing' => ['omitted'],
        'empty' => [''],
        'too long' => [str_repeat('a', 501)],
    ]);

    it('accepts a reason of exactly 500 characters', function () {
        $patient = PatientFixtures::register();

        $this->actingAs(PatientFixtures::actor('disable'))
            ->post(route('patients.disable', $patient), ['reason' => str_repeat('a', 500)])
            ->assertSessionHasNoErrors();

        expect($patient->fresh()->disable_reason)->toHaveLength(500);
    });

    it('writes exactly one disabled audit carrying the reason, with no generic updated audit', function () {
        $patient = PatientFixtures::register();
        $actor = PatientFixtures::actor('disable');
        $registration = disablingAudits($patient)->count();

        $this->actingAs($actor)->post(route('patients.disable', $patient), ['reason' => 'duplicado']);

        $audits = disablingAudits($patient)->slice($registration)->values();

        expect($audits)->toHaveCount(1)
            ->and($audits->first()->event)->toBe('disabled')
            ->and($audits->first()->new_values)->toBe(['reason' => 'duplicado'])
            ->and($audits->first()->user_id)->toBe($actor->id)
            ->and($audits->first()->batch_id)->not->toBeNull()
            ->and(Audit::query()->where('tags', 'patient:'.$patient->id)->where('event', 'updated')->count())->toBe(0);
    });

    it('rolls back the columns and the audit when the operation fails', function () {
        $patient = PatientFixtures::register();
        $audits = Audit::query()->count();
        Event::listen(AuditCustom::class, fn () => throw new RuntimeException('forced failure'));

        expect(fn () => app(PatientDisabling::class)->disable($patient->fresh(), PatientFixtures::actor('disable'), 'duplicado'))
            ->toThrow(RuntimeException::class, 'forced failure');

        expect($patient->fresh()->disabled_at)->toBeNull()
            ->and($patient->fresh()->disabled_by_id)->toBeNull()
            ->and($patient->fresh()->disable_reason)->toBeNull()
            ->and(Audit::query()->count())->toBe($audits);
    });

    it('lets only one of two racing disables take effect, leaving a single disabled audit', function () {
        $patient = PatientFixtures::register();
        $actor = PatientFixtures::actor('disable');
        $stale = Patient::query()->findOrFail($patient->id);
        $racer = Patient::query()->findOrFail($patient->id);
        $service = app(PatientDisabling::class);

        $service->disable($stale, $actor, 'primero');

        // The second request loaded the patient while it was still active.
        expect(fn () => $service->disable($racer, $actor, 'segundo'))->toThrow(ValidationException::class);

        expect($patient->fresh()->disable_reason)->toBe('primero')
            ->and(disablingAudits($patient)->where('event', 'disabled'))->toHaveCount(1);
    });
});

describe('enabling', function () {
    it('clears the three columns, audits once and brings the patient back into the active list', function () {
        $patient = PatientFixtures::registerDisabled();
        $actor = PatientFixtures::actor('disable');
        $audits = disablingAudits($patient)->count();

        $this->actingAs($actor)->post(route('patients.enable', $patient), ['reason' => 'Se deshabilito por error'])
            ->assertRedirect(route('patients.show', $patient));

        $patient->refresh();
        $new = disablingAudits($patient)->slice($audits)->values();

        expect($patient->disabled_at)->toBeNull()
            ->and($patient->disabled_by_id)->toBeNull()
            ->and($patient->disable_reason)->toBeNull()
            ->and($new)->toHaveCount(1)
            ->and($new->first()->event)->toBe('enabled')
            ->and($new->first()->new_values)->toBe(['reason' => 'Se deshabilito por error']);

        $this->actingAs($actor)->get(route('patients.index'))
            ->assertInertia(fn ($page) => $page->where('patients.data.0.uuid', $patient->uuid));
    });

    it('requires a reason and changes nothing without it', function () {
        $patient = PatientFixtures::registerDisabled();

        $this->actingAs(PatientFixtures::actor('disable'))->post(route('patients.enable', $patient), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        expect($patient->fresh()->disabled_at)->not->toBeNull();
    });

    it('overwrites the current disable state on a new disable cycle while every cycle stays audited', function () {
        $patient = PatientFixtures::register();
        $actor = PatientFixtures::actor('disable');
        $service = app(PatientDisabling::class);

        $service->disable($patient->fresh(), $actor, 'primera');
        $service->enable($patient->fresh(), $actor, 'volvio');
        $service->disable($patient->fresh(), $actor, 'segunda');

        expect($patient->fresh()->disable_reason)->toBe('segunda')
            ->and(disablingAudits($patient)->whereIn('event', ['disabled', 'enabled'])->pluck('event')->all())->toBe(['disabled', 'enabled', 'disabled'])
            ->and(disablingAudits($patient)->whereIn('event', ['disabled', 'enabled'])->pluck('batch_id')->unique())->toHaveCount(3);
    });
});

describe('state invariants outside the policy', function () {
    it('rejects disabling a disabled patient and enabling an active one for super-admin without any change', function (string $route, bool $disabled) {
        $patient = $disabled ? PatientFixtures::registerDisabled() : PatientFixtures::register();
        $before = $patient->fresh()->getAttributes();
        $audits = Audit::query()->count();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->post(route($route, $patient), ['reason' => 'Motivo valido'])
            ->assertSessionHasErrors('patient');

        expect($patient->fresh()->getAttributes())->toBe($before)
            ->and(Audit::query()->count())->toBe($audits);
    })->with([
        'disable a disabled patient' => ['patients.disable', true],
        'enable an active patient' => ['patients.enable', false],
    ]);
});

it('has no route that deletes a patient', function () {
    $methods = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'patients'))
        ->flatMap(fn ($route) => $route->methods())
        ->unique();

    expect($methods->all())->not->toContain('DELETE');
});
