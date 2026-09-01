<?php

use App\Enums\InventoryMovementType;
use App\Enums\MedicalClassification;
use App\Enums\MedicalState;
use App\Enums\Permission;
use App\Enums\SexAtBirth;
use App\Enums\TransferType;
use App\Models\InventoryMovement;
use App\Models\MedicalConsultation;
use App\Models\MedicalRegulation;
use App\Models\Medication;
use App\Models\Neighborhood;
use App\Models\Patient;
use App\Models\PatientAilment;
use App\Models\PhysicalExamination;
use App\Models\User;
use App\Models\VitalSigns;
use App\Services\Inventory\TreatmentDispensation;
use App\Support\MedicalConsultationCode;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Build the consultation aggregate fields shared by the create and update
 * requests — everything except `patient_uuid`, which only the create
 * request accepts (the patient a consultation belongs to never changes).
 *
 * @return array<string, mixed>
 */
function consultationAggregatePayload(): array
{
    return [
        'consultation' => [
            'current_condition' => fake()->sentence(),
            'diagnosis' => fake()->sentence(),
        ],
        'condition' => MedicalState::Good->value,
        'prognosis' => MedicalState::Good->value,
        'medical_classification' => MedicalClassification::Trauma->value,
        'treatment' => [],
        'vital_signs' => [
            'weight' => 70,
            'height' => 1.7,
            'blood_pressure_systolic' => 120,
            'blood_pressure_diastolic' => 80,
            'heart_rate' => 72,
            'respiratory_rate' => 16,
            'temperature' => 37,
            'oxygen_saturation' => 98,
            'glasgow' => 15,
            'glucose' => 90,
        ],
        'physical_examination' => [
            'neurological' => 'Sin alteraciones.',
            'head_neck' => 'Sin alteraciones.',
            'thorax_cardiopulmonary' => 'Sin alteraciones.',
            'abdomen' => 'Sin alteraciones.',
            'extremities' => 'Sin alteraciones.',
            'cabinet_laboratory' => 'Sin alteraciones.',
        ],
        'regulation' => null,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function validConsultationPayload(Patient $patient, array $overrides = []): array
{
    $payload = ['patient_uuid' => $patient->uuid, ...consultationAggregatePayload()];

    return array_replace_recursive($payload, $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function validConsultationUpdatePayload(array $overrides = []): array
{
    return array_replace_recursive(consultationAggregatePayload(), $overrides);
}

/**
 * Build one treatment line payload for the given medication.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function treatmentLine(Medication $medication, int $quantity = 1, array $overrides = []): array
{
    return array_replace([
        'medication_uuid' => $medication->uuid,
        'quantity_dispensed' => $quantity,
        'dose' => '500 mg',
        'frequency' => 'Cada 8 horas',
        'duration' => '5 días',
    ], $overrides);
}

/**
 * Dispense a treatment line directly through {@see TreatmentDispensation},
 * bypassing HTTP, to seed a consultation's existing treatment state before
 * exercising the update/destroy endpoints.
 */
function dispenseTreatment(MedicalConsultation $consultation, Medication $medication, int $quantity, User $actor): void
{
    DB::transaction(fn () => app(TreatmentDispensation::class)->sync(
        $consultation,
        [treatmentLine($medication, $quantity)],
        $actor,
    ));
}

/**
 * Build a Female patient with every optional block of the registration
 * aggregate populated, including a catalog enrollment, family medical unit,
 * and a neighborhood for `withCompleteProfile()` to link the contact to.
 */
function patientWithCompleteProfile(): Patient
{
    Neighborhood::factory()->create();

    return Patient::factory()
        ->withEnrollment()
        ->withFamilyMedicalUnit()
        ->withCompleteProfile()
        ->create(['sex_at_birth' => SexAtBirth::Female]);
}

/**
 * Assert the `patientProfile` prop mirrors the patient's complete profile.
 */
function assertCompletePatientProfile(Assert $page, Patient $patient): Assert
{
    $patient->load(['enrollment', 'familyMedicalUnit', 'contactInformation.neighborhood.zipCode.municipality', 'otherAilments', 'gynecologicalHistory']);

    return $page
        ->where('patientProfile.first_name', $patient->first_name)
        ->where('patientProfile.birth_date', $patient->birth_date->format('Y-m-d'))
        ->where('patientProfile.age', $patient->birth_date->age)
        ->where('patientProfile.sex_at_birth', SexAtBirth::Female->value)
        ->where('patientProfile.marital_status', $patient->marital_status->value)
        ->where('patientProfile.blood_type', $patient->blood_type->value)
        ->where('patientProfile.enrollment', $patient->enrollment->name)
        ->where('patientProfile.enrollment_number', $patient->enrollment_number)
        ->where('patientProfile.external_enrollment', null)
        ->where('patientProfile.family_medical_unit.name', $patient->familyMedicalUnit->name)
        ->where('patientProfile.other_family_medical_unit', null)
        ->where('patientProfile.social_security_number', $patient->social_security_number)
        ->where('patientProfile.contact_information.personal_email', $patient->contactInformation->personal_email)
        ->where('patientProfile.contact_information.neighborhood', $patient->contactInformation->neighborhood->name)
        ->where('patientProfile.contact_information.zip_code', $patient->contactInformation->neighborhood->zip_code)
        ->where('patientProfile.contact_information.municipality', $patient->contactInformation->neighborhood->zipCode->municipality->name)
        ->has('patientProfile.emergency_contacts', $patient->emergencyContacts()->count())
        ->has('patientProfile.ailments', $patient->ailments()->count())
        ->where('patientProfile.other_ailments', $patient->otherAilments?->only(['surgeries', 'allergies', 'others']))
        ->where('patientProfile.gynecological_history.menarche', $patient->gynecologicalHistory->menarche)
        ->where('patientProfile.gynecological_history.last_cycle_date', $patient->gynecologicalHistory->last_cycle_date->format('Y-m-d'));
}

dataset('consultationRoutes', [
    'opening the create page' => ['get', 'consultations.create', false],
    'creating a consultation' => ['post', 'consultations.store', false],
    'viewing a consultation' => ['get', 'consultations.show', true],
    'editing a consultation' => ['get', 'consultations.edit', true],
    'updating a consultation' => ['put', 'consultations.update', true],
    'deleting a consultation' => ['delete', 'consultations.destroy', true],
]);

describe('consultation pages and mutation navigation', function () {
    it('renders the create page with the selected patient and enum options', function () {
        $physician = User::factory()->withPermissions(Permission::ConsultationsCreate)->create();
        $patient = Patient::factory()->create();

        $this->actingAs($physician)->get(route('consultations.create', ['patient' => $patient->uuid]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('consultations/Create', false)
                ->where('patient.uuid', $patient->uuid)
                ->has('medicalStateOptions')
                ->has('medicalClassificationOptions')
                ->has('transferTypeOptions'));
    });

    it('rejects a create page without a valid patient', function () {
        $physician = User::factory()->withPermissions(Permission::ConsultationsCreate)->create();
        $this->actingAs($physician)->get(route('consultations.create'))->assertUnprocessable();
        $this->actingAs($physician)->get(route('consultations.create', ['patient' => 'not-found']))->assertUnprocessable();
        $this->actingAs($physician)->get(route('consultations.create', ['patient' => (string) Str::uuid()]))->assertNotFound();
    });

    it('rejects a create page when create permission is missing', function () {
        $patient = Patient::factory()->create();
        $this->actingAs(User::factory()->create())->get(route('consultations.create', ['patient' => $patient->uuid]))->assertForbidden();
    });

    it('allows a non-owner with view permission to read the complete detail without an edit action', function () {
        $consultation = MedicalConsultation::factory()->withRegulation()->create();
        $viewer = User::factory()->withPermissions(Permission::ConsultationsView)->create();

        $this->actingAs($viewer)->get(route('consultations.show', $consultation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('consultations/Show', false)
                ->where('consultation.uuid', $consultation->uuid)
                ->where('consultation.can.update', false)
                ->where('consultation.can.delete', false)
                ->has('consultation.vital_signs')
                ->has('consultation.physical_examination')
                ->has('consultation.regulation'));
    });

    it('prefills the edit page for the owner and blocks a non-owner', function () {
        $owner = User::factory()->withPermissions(Permission::ConsultationsUpdate)->create();
        $consultation = MedicalConsultation::factory()->withRegulation()->create(['physician_id' => $owner->id]);
        $other = User::factory()->withPermissions(Permission::ConsultationsUpdate)->create();

        $this->actingAs($owner)->get(route('consultations.edit', $consultation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('consultations/Edit', false)
                ->where('consultation.uuid', $consultation->uuid)
                ->where('consultation.can.update', true)
                ->has('consultation.regulation')
                ->has('consultation.treatment'));
        $this->actingAs($other)->get(route('consultations.edit', $consultation))->assertForbidden();
    });

    it('includes the complete patient profile on the create page', function () {
        $physician = User::factory()->withPermissions(Permission::ConsultationsCreate)->create();
        $patient = patientWithCompleteProfile();

        $this->actingAs($physician)->get(route('consultations.create', ['patient' => $patient->uuid]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => assertCompletePatientProfile(
                $page->component('consultations/Create', false)->where('patient.uuid', $patient->uuid),
                $patient,
            ));
    });

    it('includes the complete patient profile on the consultation pages', function (string $routeName, string $component) {
        $physician = User::factory()->withPermissions(Permission::ConsultationsView, Permission::ConsultationsUpdate)->create();
        $patient = patientWithCompleteProfile();
        $ailment = $patient->ailments()->orderBy('ailment_type')->first() ?? PatientAilment::factory()->for($patient)->create();
        $emergencyContact = $patient->emergencyContacts()->orderBy('id')->first();
        $consultation = MedicalConsultation::factory()->withoutRegulation()->create(['patient_id' => $patient->id, 'physician_id' => $physician->id]);

        $this->actingAs($physician)->get(route($routeName, $consultation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => assertCompletePatientProfile($page->component($component, false), $patient)
                ->where('patientProfile.emergency_contacts.0', [
                    'name' => $emergencyContact->name,
                    'phone_number' => $emergencyContact->phone_number,
                    'kinship_type' => $emergencyContact->kinship_type->value,
                ])
                ->where('patientProfile.ailments.0', [
                    'ailment_type' => $ailment->ailment_type->value,
                    'diagnosed_at' => $ailment->diagnosed_at->format('Y-m-d'),
                    'treatment_notes' => $ailment->treatment_notes,
                ]));
    })->with([
        'viewing a consultation' => ['consultations.show', 'consultations/Show'],
        'editing a consultation' => ['consultations.edit', 'consultations/Edit'],
    ]);

    it('sends empty profile blocks for a patient without optional data', function () {
        $physician = User::factory()->withPermissions(Permission::ConsultationsCreate)->create();
        $patient = Patient::factory()->create(['sex_at_birth' => SexAtBirth::Male]);

        $this->actingAs($physician)->get(route('consultations.create', ['patient' => $patient->uuid]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('consultations/Create', false)
                ->where('patientProfile.enrollment', null)
                ->where('patientProfile.enrollment_number', null)
                ->where('patientProfile.external_enrollment', $patient->external_enrollment)
                ->where('patientProfile.family_medical_unit', null)
                ->where('patientProfile.other_family_medical_unit', $patient->other_family_medical_unit)
                ->where('patientProfile.contact_information', null)
                ->where('patientProfile.emergency_contacts', [])
                ->where('patientProfile.ailments', [])
                ->where('patientProfile.other_ailments', null)
                ->where('patientProfile.gynecological_history', null));
    });

    it('redirects update and delete to the dashboard with action-specific flash', function () {
        $physician = User::factory()->withPermissions(Permission::ConsultationsUpdate, Permission::ConsultationsDelete)->create();
        $consultation = MedicalConsultation::factory()->withoutRegulation()->create(['physician_id' => $physician->id]);

        $this->actingAs($physician)->put(route('consultations.update', $consultation), validConsultationUpdatePayload())
            ->assertRedirect(route('dashboard.index'))
            ->assertInertiaFlash('consultation', ['uuid' => $consultation->uuid, 'code' => $consultation->code, 'action' => 'updated']);
        $this->actingAs($physician)->delete(route('consultations.destroy', $consultation))
            ->assertRedirect(route('dashboard.index'))
            ->assertInertiaFlash('consultation', ['uuid' => $consultation->uuid, 'code' => $consultation->code, 'action' => 'deleted']);
    });
});

describe('creating a consultation', function () {
    it('persists the consultation with its vital signs and physical examination when no regulation is submitted', function () {
        $physician = User::factory()->withPermissions(Permission::ConsultationsCreate)->create();
        $patient = Patient::factory()->create();

        $response = $this->actingAs($physician)->post(route('consultations.store'), validConsultationPayload($patient));

        $response->assertRedirect(route('dashboard.index'));
        $consultation = MedicalConsultation::query()->sole();
        expect($consultation->patient_id)->toBe($patient->id)
            ->and($consultation->physician_id)->toBe($physician->id)
            ->and($consultation->vitalSigns)->not->toBeNull()
            ->and($consultation->physicalExamination)->not->toBeNull()
            ->and($consultation->regulation)->toBeNull();
    });

    it('persists a regulation section when one is submitted', function () {
        $physician = User::factory()->withPermissions(Permission::ConsultationsCreate)->create();
        $patient = Patient::factory()->create();

        $payload = validConsultationPayload($patient, [
            'regulation' => [
                'transfer_type' => TransferType::Institute->value,
                'regulated_at' => now()->toDateTimeString(),
                'ambulance_registration' => 'AMB-001',
                'regulation_number' => null,
                'clinic_id' => null,
                'receiver_physician' => null,
            ],
        ]);

        $this->actingAs($physician)->post(route('consultations.store'), $payload);

        $consultation = MedicalConsultation::query()->sole();
        expect($consultation->regulation)->not->toBeNull()
            ->and($consultation->regulation->transfer_type)->toBe(TransferType::Institute);
    });

    it('sets the authenticated user as physician_id and rejects a client-supplied physician_id as an unknown field', function () {
        $physician = User::factory()->withPermissions(Permission::ConsultationsCreate)->create();
        $patient = Patient::factory()->create();
        $otherUser = User::factory()->create();

        $response = $this->actingAs($physician)->post(
            route('consultations.store'),
            validConsultationPayload($patient, ['physician_id' => $otherUser->id])
        );

        $response->assertSessionHasErrors(['physician_id']);
        expect(MedicalConsultation::query()->count())->toBe(0);
    });

    it('flashes the created consultation uuid and code', function () {
        $physician = User::factory()->withPermissions(Permission::ConsultationsCreate)->create();
        $patient = Patient::factory()->create();

        $response = $this->actingAs($physician)->post(route('consultations.store'), validConsultationPayload($patient));

        $consultation = MedicalConsultation::query()->sole();
        $response->assertInertiaFlash('consultation', ['uuid' => $consultation->uuid, 'code' => $consultation->code, 'action' => 'created']);
    });

    it('accepts whole-number and single/double decimal vital sign inputs', function () {
        $physician = User::factory()->withPermissions(Permission::ConsultationsCreate)->create();
        $patient = Patient::factory()->create();

        $payload = validConsultationPayload($patient, [
            'vital_signs' => ['weight' => 70, 'height' => 1.7, 'temperature' => 37],
        ]);

        $response = $this->actingAs($physician)->post(route('consultations.store'), $payload);

        $response->assertSessionHasNoErrors();
        expect(MedicalConsultation::query()->count())->toBe(1);
    });

    it('rejects a vital sign value with more decimal places than the field allows', function (string $field, float $value) {
        $physician = User::factory()->withPermissions(Permission::ConsultationsCreate)->create();
        $patient = Patient::factory()->create();

        $payload = validConsultationPayload($patient, ['vital_signs' => [$field => $value]]);

        $response = $this->actingAs($physician)->post(route('consultations.store'), $payload);

        $response->assertSessionHasErrors(["vital_signs.{$field}"]);
        expect(MedicalConsultation::query()->count())->toBe(0);
    })->with([
        'weight (3 decimals, max 2)' => ['weight', 70.123],
        'height (3 decimals, max 2)' => ['height', 1.234],
        'temperature (2 decimals, max 1)' => ['temperature', 37.12],
    ]);

    it('rejects a diastolic reading that is not strictly below the systolic reading', function (int $systolic, int $diastolic) {
        $physician = User::factory()->withPermissions(Permission::ConsultationsCreate)->create();
        $patient = Patient::factory()->create();

        $payload = validConsultationPayload($patient, [
            'vital_signs' => ['blood_pressure_systolic' => $systolic, 'blood_pressure_diastolic' => $diastolic],
        ]);

        $response = $this->actingAs($physician)->post(route('consultations.store'), $payload);

        $response->assertSessionHasErrors([
            'vital_signs.blood_pressure_diastolic' => __('modules/consultations/management.custom.diastolic_not_less_than_systolic'),
        ]);
        expect(MedicalConsultation::query()->count())->toBe(0);
    })->with([
        'diastolic equal to systolic' => [120, 120],
        'diastolic greater than systolic' => [110, 120],
    ]);
});

describe('treatment lines dispense inventory', function () {
    it('accepts an empty treatment array and dispenses no stock', function () {
        $physician = User::factory()->withPermissions(Permission::ConsultationsCreate)->create();
        $patient = Patient::factory()->create();

        $response = $this->actingAs($physician)->post(
            route('consultations.store'),
            validConsultationPayload($patient, ['treatment' => []])
        );

        $response->assertSessionHasNoErrors();
        expect(MedicalConsultation::query()->count())->toBe(1)
            ->and(InventoryMovement::query()->count())->toBe(0);
    });

    it('rejects a malformed treatment entry missing a required field', function () {
        $physician = User::factory()->withPermissions(Permission::ConsultationsCreate)->create();
        $patient = Patient::factory()->create();
        $medication = Medication::factory()->create();

        $line = treatmentLine($medication);
        unset($line['dose']);

        $response = $this->actingAs($physician)->post(
            route('consultations.store'),
            validConsultationPayload($patient, ['treatment' => [$line]])
        );

        $response->assertSessionHasErrors(['treatment.0.dose']);
        expect(MedicalConsultation::query()->count())->toBe(0);
    });

    it('dispenses stock and records one Dispensation movement per treatment line on create', function () {
        $physician = User::factory()->withPermissions(Permission::ConsultationsCreate)->create();
        $patient = Patient::factory()->create();
        $medication = Medication::factory()->create(['current_stock' => 10]);

        $response = $this->actingAs($physician)->post(
            route('consultations.store'),
            validConsultationPayload($patient, ['treatment' => [treatmentLine($medication, 3)]])
        );

        $response->assertSessionHasNoErrors();
        $consultation = MedicalConsultation::query()->sole();

        expect($medication->fresh()->current_stock)->toBe(7)
            ->and($consultation->treatments()->sole()->quantity_dispensed)->toBe(3);

        $movement = InventoryMovement::query()->sole();
        expect($movement->type)->toBe(InventoryMovementType::Dispensation)
            ->and($movement->quantity)->toBe(-3)
            ->and($movement->stock_after)->toBe(7)
            ->and($movement->medical_consultation_id)->toBe($consultation->id)
            ->and($movement->medical_consultation_code)->toBe($consultation->code);
    });

    it('rejects a treatment line requesting more than the available stock with a per-line 422 and no stock change', function () {
        $physician = User::factory()->withPermissions(Permission::ConsultationsCreate)->create();
        $patient = Patient::factory()->create();
        $medication = Medication::factory()->create(['current_stock' => 2]);

        $response = $this->actingAs($physician)->post(
            route('consultations.store'),
            validConsultationPayload($patient, ['treatment' => [treatmentLine($medication, 5)]])
        );

        $response->assertSessionHasErrors(['treatment.0.quantity_dispensed']);
        expect(MedicalConsultation::query()->count())->toBe(0)
            ->and($medication->fresh()->current_stock)->toBe(2)
            ->and(InventoryMovement::query()->count())->toBe(0);
    });

    it('rejects a duplicate medication referenced by two treatment lines', function () {
        $physician = User::factory()->withPermissions(Permission::ConsultationsCreate)->create();
        $patient = Patient::factory()->create();
        $medication = Medication::factory()->create(['current_stock' => 10]);

        $response = $this->actingAs($physician)->post(
            route('consultations.store'),
            validConsultationPayload($patient, [
                'treatment' => [treatmentLine($medication, 1), treatmentLine($medication, 2)],
            ])
        );

        $response->assertSessionHasErrors(['treatment.0.medication_uuid', 'treatment.1.medication_uuid']);
        expect(MedicalConsultation::query()->count())->toBe(0);
    });

    it('rejects an inactive medication referenced by a new treatment line on create', function () {
        $physician = User::factory()->withPermissions(Permission::ConsultationsCreate)->create();
        $patient = Patient::factory()->create();
        $medication = Medication::factory()->inactive()->create();

        $response = $this->actingAs($physician)->post(
            route('consultations.store'),
            validConsultationPayload($patient, ['treatment' => [treatmentLine($medication)]])
        );

        $response->assertSessionHasErrors(['treatment.0.medication_uuid']);
        expect(MedicalConsultation::query()->count())->toBe(0);
    });

    it('dispenses the delta as an additional Dispensation when a treatment line quantity increases on update', function () {
        $physician = User::factory()->withPermissions(Permission::ConsultationsUpdate)->create();
        $medication = Medication::factory()->create(['current_stock' => 20]);
        $consultation = MedicalConsultation::factory()->withoutRegulation()->create(['physician_id' => $physician->id]);
        dispenseTreatment($consultation, $medication, 2, $physician);
        expect($medication->fresh()->current_stock)->toBe(18);

        $response = $this->actingAs($physician)->put(
            route('consultations.update', $consultation),
            validConsultationUpdatePayload(['treatment' => [treatmentLine($medication, 5)]])
        );

        $response->assertSessionHasNoErrors();
        expect($medication->fresh()->current_stock)->toBe(15)
            ->and($consultation->treatments()->sole()->quantity_dispensed)->toBe(5);

        $lastMovement = InventoryMovement::query()->where('medication_id', $medication->id)->orderBy('id')->get()->last();
        expect($lastMovement->type)->toBe(InventoryMovementType::Dispensation)
            ->and($lastMovement->quantity)->toBe(-3);
    });

    it('restores the difference via a DispensationReversal when a treatment line quantity decreases on update', function () {
        $physician = User::factory()->withPermissions(Permission::ConsultationsUpdate)->create();
        $medication = Medication::factory()->create(['current_stock' => 20]);
        $consultation = MedicalConsultation::factory()->withoutRegulation()->create(['physician_id' => $physician->id]);
        dispenseTreatment($consultation, $medication, 6, $physician);
        expect($medication->fresh()->current_stock)->toBe(14);

        $response = $this->actingAs($physician)->put(
            route('consultations.update', $consultation),
            validConsultationUpdatePayload(['treatment' => [treatmentLine($medication, 2)]])
        );

        $response->assertSessionHasNoErrors();
        expect($medication->fresh()->current_stock)->toBe(18)
            ->and($consultation->treatments()->sole()->quantity_dispensed)->toBe(2);

        $reversal = InventoryMovement::query()
            ->where('medication_id', $medication->id)
            ->where('type', InventoryMovementType::DispensationReversal)
            ->sole();
        expect($reversal->quantity)->toBe(4);
    });

    it('restores the full quantity via a DispensationReversal when a treatment line is removed on update', function () {
        $physician = User::factory()->withPermissions(Permission::ConsultationsUpdate)->create();
        $medication = Medication::factory()->create(['current_stock' => 20]);
        $consultation = MedicalConsultation::factory()->withoutRegulation()->create(['physician_id' => $physician->id]);
        dispenseTreatment($consultation, $medication, 4, $physician);
        expect($medication->fresh()->current_stock)->toBe(16);

        $response = $this->actingAs($physician)->put(
            route('consultations.update', $consultation),
            validConsultationUpdatePayload(['treatment' => []])
        );

        $response->assertSessionHasNoErrors();
        expect($medication->fresh()->current_stock)->toBe(20)
            ->and($consultation->treatments()->count())->toBe(0);

        $reversal = InventoryMovement::query()
            ->where('medication_id', $medication->id)
            ->where('type', InventoryMovementType::DispensationReversal)
            ->sole();
        expect($reversal->quantity)->toBe(4);
    });

    it('keeps an existing treatment line editable after its medication is deactivated', function () {
        $physician = User::factory()->withPermissions(Permission::ConsultationsUpdate)->create();
        $medication = Medication::factory()->create(['current_stock' => 20]);
        $consultation = MedicalConsultation::factory()->withoutRegulation()->create(['physician_id' => $physician->id]);
        dispenseTreatment($consultation, $medication, 2, $physician);
        $medication->update(['is_active' => false]);

        $response = $this->actingAs($physician)->put(
            route('consultations.update', $consultation),
            validConsultationUpdatePayload(['treatment' => [treatmentLine($medication, 3)]])
        );

        $response->assertSessionHasNoErrors();
        expect($consultation->treatments()->sole()->quantity_dispensed)->toBe(3)
            ->and($medication->fresh()->current_stock)->toBe(17);
    });

    it('rejects a deactivated medication as a newly added treatment line on update', function () {
        $physician = User::factory()->withPermissions(Permission::ConsultationsUpdate)->create();
        $linkedMedication = Medication::factory()->create(['current_stock' => 20]);
        $medication = Medication::factory()->inactive()->create();
        $consultation = MedicalConsultation::factory()->withoutRegulation()->create(['physician_id' => $physician->id]);
        dispenseTreatment($consultation, $linkedMedication, 2, $physician);

        $response = $this->actingAs($physician)->put(
            route('consultations.update', $consultation),
            validConsultationUpdatePayload(['treatment' => [
                treatmentLine($linkedMedication, 2),
                treatmentLine($medication),
            ]])
        );

        $response->assertSessionHasErrors(['treatment.1.medication_uuid'])
            ->assertSessionDoesntHaveErrors(['treatment.0.medication_uuid']);
    });

    it('restores all dispensed stock and preserves movements with a null consultation id and the code snapshot on delete', function () {
        $physician = User::factory()->withPermissions(Permission::ConsultationsDelete, Permission::ConsultationsUpdate)->create();
        $medication = Medication::factory()->create(['current_stock' => 20]);
        $consultation = MedicalConsultation::factory()->withoutRegulation()->create(['physician_id' => $physician->id]);
        dispenseTreatment($consultation, $medication, 6, $physician);
        $code = $consultation->code;

        $response = $this->actingAs($physician)->delete(route('consultations.destroy', $consultation));

        $response->assertRedirect(route('dashboard.index'));
        expect($medication->fresh()->current_stock)->toBe(20)
            ->and(MedicalConsultation::query()->count())->toBe(0);

        $movements = InventoryMovement::query()->where('medication_id', $medication->id)->orderBy('id')->get();
        expect($movements)->toHaveCount(2);

        foreach ($movements as $movement) {
            expect($movement->medical_consultation_id)->toBeNull()
                ->and($movement->medical_consultation_code)->toBe($code);
        }
    });

    it('sends the medication catalog and each treatment line linked medication on the edit page', function () {
        $physician = User::factory()->withPermissions(Permission::ConsultationsUpdate)->create();
        $medication = Medication::factory()->create(['current_stock' => 10]);
        $consultation = MedicalConsultation::factory()->withoutRegulation()->create(['physician_id' => $physician->id]);
        dispenseTreatment($consultation, $medication, 1, $physician);

        $this->actingAs($physician)->get(route('consultations.edit', $consultation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('consultations/Edit', false)
                ->has('medicationOptions')
                ->where('consultation.treatment.0.medication.uuid', $medication->uuid)
                ->where('consultation.treatment.0.quantity_dispensed', 1));
    });
});

describe('consultation code generation', function () {
    it('generates a code matching the MC-YYMMDD-NNNN format', function () {
        $physician = User::factory()->withPermissions(Permission::ConsultationsCreate)->create();
        $patient = Patient::factory()->create();

        $this->actingAs($physician)->post(route('consultations.store'), validConsultationPayload($patient));

        $consultation = MedicalConsultation::query()->sole();
        expect($consultation->code)->toMatch('/^MC-\d{6}-\d{4}$/')
            ->and(mb_strlen($consultation->code))->toBe(14);
    });

    it('increments the daily sequence for consultations created on the same day', function () {
        $physician = User::factory()->withPermissions(Permission::ConsultationsCreate)->create();
        $patient = Patient::factory()->create();

        $this->actingAs($physician)->post(route('consultations.store'), validConsultationPayload($patient));
        $this->actingAs($physician)->post(route('consultations.store'), validConsultationPayload($patient));

        $codes = MedicalConsultation::query()->orderBy('id')->pluck('code')->all();
        $today = now('America/Mexico_City')->format('ymd');

        expect($codes)->toBe(["MC-{$today}-0001", "MC-{$today}-0002"]);
    });

    it('computes the daily boundary in America/Mexico_City rather than UTC', function () {
        $physician = User::factory()->withPermissions(Permission::ConsultationsCreate)->create();
        $patient = Patient::factory()->create();

        // 2026-07-14 03:00 UTC is 2026-07-13 21:00 in America/Mexico_City
        // (fixed UTC-6, no DST since 2022) — still the previous calendar day.
        $this->travelTo(Carbon::create(2026, 7, 14, 3, 0, 0, 'UTC'));
        $this->actingAs($physician)->post(route('consultations.store'), validConsultationPayload($patient));

        // 2026-07-14 07:00 UTC is 2026-07-14 01:00 in America/Mexico_City —
        // now past midnight, so the sequence resets on a new prefix.
        $this->travelTo(Carbon::create(2026, 7, 14, 7, 0, 0, 'UTC'));
        $this->actingAs($physician)->post(route('consultations.store'), validConsultationPayload($patient));

        $this->travelBack();

        $codes = MedicalConsultation::query()->orderBy('id')->pluck('code')->all();
        expect($codes)->toBe(['MC-260713-0001', 'MC-260714-0001']);
    });

    it('refuses to generate a code outside a database transaction', function () {
        DB::shouldReceive('transactionLevel')->once()->andReturn(0);

        MedicalConsultationCode::next();
    })->throws(LogicException::class);
});

describe('rollback on child failure', function () {
    it('rolls back the entire aggregate when vital signs creation fails inside the transaction', function () {
        Exceptions::fake();
        VitalSigns::creating(function (): void {
            throw new RuntimeException('forced vital signs failure');
        });

        $physician = User::factory()->withPermissions(Permission::ConsultationsCreate)->create();
        $patient = Patient::factory()->create();

        $this->actingAs($physician)->post(route('consultations.store'), validConsultationPayload($patient));

        Exceptions::assertReported(RuntimeException::class);
        expect(MedicalConsultation::query()->count())->toBe(0);
    });
});

describe('updating a consultation', function () {
    it('updates the current condition, diagnosis, and vital signs', function () {
        $physician = User::factory()->withPermissions(Permission::ConsultationsUpdate)->create();
        $patient = Patient::factory()->create();
        $consultation = MedicalConsultation::factory()->withoutRegulation()->create([
            'physician_id' => $physician->id,
            'patient_id' => $patient->id,
        ]);

        $payload = validConsultationUpdatePayload([
            'consultation' => ['current_condition' => 'Updated condition', 'diagnosis' => 'Updated diagnosis'],
            'vital_signs' => ['heart_rate' => 100],
        ]);

        $response = $this->actingAs($physician)->put(route('consultations.update', $consultation), $payload);

        $response->assertRedirect(route('dashboard.index'));
        $consultation->refresh();
        expect($consultation->current_condition)->toBe('Updated condition')
            ->and($consultation->diagnosis)->toBe('Updated diagnosis')
            ->and($consultation->vitalSigns->fresh()->heart_rate)->toBe(100);
    });

    it('rejects patient_uuid as an unknown field and leaves the patient unchanged', function () {
        $physician = User::factory()->withPermissions(Permission::ConsultationsUpdate)->create();
        $patient = Patient::factory()->create();
        $otherPatient = Patient::factory()->create();
        $consultation = MedicalConsultation::factory()->withoutRegulation()->create([
            'physician_id' => $physician->id,
            'patient_id' => $patient->id,
        ]);

        $payload = validConsultationUpdatePayload(['patient_uuid' => $otherPatient->uuid]);

        $response = $this->actingAs($physician)->put(route('consultations.update', $consultation), $payload);

        $response->assertSessionHasErrors(['patient_uuid']);
        expect($consultation->fresh()->patient_id)->toBe($patient->id);
    });

    it('never changes the code or physician on update', function () {
        $physician = User::factory()->withPermissions(Permission::ConsultationsUpdate)->create();
        $consultation = MedicalConsultation::factory()->withoutRegulation()->create(['physician_id' => $physician->id]);
        $originalCode = $consultation->code;

        $this->actingAs($physician)->put(route('consultations.update', $consultation), validConsultationUpdatePayload());

        $consultation->refresh();
        expect($consultation->code)->toBe($originalCode)
            ->and($consultation->physician_id)->toBe($physician->id);
    });

    it('adds a regulation section on update when none existed before', function () {
        $physician = User::factory()->withPermissions(Permission::ConsultationsUpdate)->create();
        $consultation = MedicalConsultation::factory()->withoutRegulation()->create(['physician_id' => $physician->id]);
        expect($consultation->regulation)->toBeNull();

        $payload = validConsultationUpdatePayload([
            'regulation' => [
                'transfer_type' => TransferType::Institute->value,
                'regulated_at' => now()->toDateTimeString(),
                'ambulance_registration' => 'AMB-100',
                'regulation_number' => null,
                'clinic_id' => null,
                'receiver_physician' => null,
            ],
        ]);

        $this->actingAs($physician)->put(route('consultations.update', $consultation), $payload);

        expect($consultation->refresh()->regulation)->not->toBeNull()
            ->and($consultation->regulation->transfer_type)->toBe(TransferType::Institute);
    });

    it('updates an existing regulation section on update', function () {
        $physician = User::factory()->withPermissions(Permission::ConsultationsUpdate)->create();
        $consultation = MedicalConsultation::factory()->withRegulation()->create(['physician_id' => $physician->id]);

        $payload = validConsultationUpdatePayload([
            'regulation' => [
                'transfer_type' => TransferType::OwnResources->value,
                'regulated_at' => now()->toDateTimeString(),
                'ambulance_registration' => null,
                'regulation_number' => 'REG-999',
                'clinic_id' => null,
                'receiver_physician' => null,
            ],
        ]);

        $this->actingAs($physician)->put(route('consultations.update', $consultation), $payload);

        expect($consultation->refresh()->regulation->transfer_type)->toBe(TransferType::OwnResources)
            ->and($consultation->regulation->regulation_number)->toBe('REG-999');
        expect(MedicalRegulation::query()->count())->toBe(1);
    });

    it('removes the regulation section on update when omitted', function () {
        $physician = User::factory()->withPermissions(Permission::ConsultationsUpdate)->create();
        $consultation = MedicalConsultation::factory()->withRegulation()->create(['physician_id' => $physician->id]);
        expect($consultation->regulation)->not->toBeNull();

        $this->actingAs($physician)->put(
            route('consultations.update', $consultation),
            validConsultationUpdatePayload(['regulation' => null])
        );

        expect($consultation->refresh()->regulation)->toBeNull();
        expect(MedicalRegulation::query()->count())->toBe(0);
    });
});

describe('deleting a consultation', function () {
    it('hard deletes the consultation and cascades all child rows', function () {
        $physician = User::factory()->withPermissions(Permission::ConsultationsDelete)->create();
        $consultation = MedicalConsultation::factory()->withRegulation()->create(['physician_id' => $physician->id]);

        $response = $this->actingAs($physician)->delete(route('consultations.destroy', $consultation));

        $response->assertRedirect(route('dashboard.index'));
        expect(MedicalConsultation::query()->count())->toBe(0)
            ->and(VitalSigns::query()->count())->toBe(0)
            ->and(PhysicalExamination::query()->count())->toBe(0)
            ->and(MedicalRegulation::query()->count())->toBe(0);
    });
});

describe('guest access', function () {
    it('redirects a guest to login instead of returning 403', function (string $method, string $routeName, bool $needsConsultation) {
        $consultation = $needsConsultation ? MedicalConsultation::factory()->withoutRegulation()->create() : null;
        $uri = $needsConsultation ? route($routeName, $consultation) : route($routeName);

        $response = $this->{$method}($uri);

        $response->assertRedirect(route('auth.login'));
    })->with('consultationRoutes');
});

describe('permission and ownership boundary', function () {
    it('denies creating a consultation without consultations.create', function () {
        $caller = User::factory()->create();
        $patient = Patient::factory()->create();

        $response = $this->actingAs($caller)->post(route('consultations.store'), validConsultationPayload($patient));

        $response->assertForbidden();
        expect(MedicalConsultation::query()->count())->toBe(0);
    });

    it('denies a permissioned non-owner from updating', function () {
        $owner = User::factory()->create();
        $consultation = MedicalConsultation::factory()->withoutRegulation()->create(['physician_id' => $owner->id]);
        $caller = User::factory()->withPermissions(Permission::ConsultationsUpdate)->create();

        $response = $this->actingAs($caller)->put(route('consultations.update', $consultation), validConsultationUpdatePayload());

        $response->assertForbidden();
    });

    it('denies a permissioned non-owner from deleting', function () {
        $owner = User::factory()->create();
        $consultation = MedicalConsultation::factory()->withoutRegulation()->create(['physician_id' => $owner->id]);
        $caller = User::factory()->withPermissions(Permission::ConsultationsDelete)->create();

        $response = $this->actingAs($caller)->delete(route('consultations.destroy', $consultation));

        $response->assertForbidden();
        expect(MedicalConsultation::query()->count())->toBe(1);
    });

    it('denies the owner from updating without consultations.update', function () {
        $owner = User::factory()->create();
        $consultation = MedicalConsultation::factory()->withoutRegulation()->create(['physician_id' => $owner->id]);

        $response = $this->actingAs($owner)->put(route('consultations.update', $consultation), validConsultationUpdatePayload());

        $response->assertForbidden();
    });

    it('denies the owner from deleting without consultations.delete', function () {
        $owner = User::factory()->create();
        $consultation = MedicalConsultation::factory()->withoutRegulation()->create(['physician_id' => $owner->id]);

        $response = $this->actingAs($owner)->delete(route('consultations.destroy', $consultation));

        $response->assertForbidden();
        expect(MedicalConsultation::query()->count())->toBe(1);
    });

    it("allows a super-admin with zero direct permissions to update someone else's consultation", function () {
        $admin = User::factory()->superAdmin()->create();
        $owner = User::factory()->create();
        $consultation = MedicalConsultation::factory()->withoutRegulation()->create(['physician_id' => $owner->id]);

        $response = $this->actingAs($admin)->put(route('consultations.update', $consultation), validConsultationUpdatePayload());

        $response->assertRedirect(route('dashboard.index'));
        $response->assertSessionHasNoErrors();
    });

    it("allows a super-admin with zero direct permissions to delete someone else's consultation", function () {
        $admin = User::factory()->superAdmin()->create();
        $owner = User::factory()->create();
        $consultation = MedicalConsultation::factory()->withoutRegulation()->create(['physician_id' => $owner->id]);

        $response = $this->actingAs($admin)->delete(route('consultations.destroy', $consultation));

        $response->assertRedirect(route('dashboard.index'));
        expect(MedicalConsultation::query()->count())->toBe(0);
    });
});
