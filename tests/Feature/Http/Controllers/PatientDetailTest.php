<?php

use App\Enums\Permission;
use App\Models\MedicalConsultation;
use App\Models\User;
use Inertia\Testing\AssertableInertia;
use Tests\Support\PatientFixtures;

function profileViewer(string $kind): User
{
    return match ($kind) {
        'consultations.view only' => User::factory()->withPermissions(Permission::ConsultationsView)->create(),
        'patients.view' => User::factory()->withPermissions(Permission::ConsultationsView, Permission::PatientsView)->create(),
        'super-admin' => User::factory()->superAdmin()->create(),
    };
}

describe('profile exposure', function () {
    it('hides the social security number, emails and phones from a viewer without patients.view on a consultation page', function () {
        $patient = PatientFixtures::register(['contact_information' => ['institutional_email' => 'inst@imss.test']]);
        $consultation = MedicalConsultation::factory()->withoutRegulation()->create(['patient_id' => $patient->id]);

        $this->actingAs(profileViewer('consultations.view only'))->get(route('consultations.show', $consultation))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('patientProfile.social_security_number', null)
                ->where('patientProfile.contact_information.phone_number', null)
                ->where('patientProfile.contact_information.personal_email', null)
                ->where('patientProfile.contact_information.institutional_email', null)
                ->where('patientProfile.emergency_contacts.0.phone_number', null)
                ->where('patientProfile.first_name', $patient->first_name)
                ->where('patientProfile.emergency_contacts.0.name', 'Ana Lopez'));
    });

    it('includes the sensitive fields for patients.view and super-admin on a consultation page', function (string $kind) {
        $patient = PatientFixtures::register(['contact_information' => ['institutional_email' => 'inst@imss.test']]);
        $consultation = MedicalConsultation::factory()->withoutRegulation()->create(['patient_id' => $patient->id]);

        $this->actingAs(profileViewer($kind))->get(route('consultations.show', $consultation))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('patientProfile.social_security_number', $patient->social_security_number)
                ->where('patientProfile.contact_information.phone_number', '+5212220000001')
                ->where('patientProfile.contact_information.institutional_email', 'inst@imss.test')
                ->where('patientProfile.emergency_contacts.0.phone_number', '+5212220000002'));
    })->with(['patients.view', 'super-admin']);

    it('includes the sensitive fields on the patient detail page', function () {
        $patient = PatientFixtures::register();

        $this->actingAs(PatientFixtures::actor('view'))->get(route('patients.show', $patient))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('patient.profile.social_security_number', $patient->social_security_number)
                ->where('patient.profile.contact_information.phone_number', '+5212220000001'));
    });
});

describe('patient detail', function () {
    it('returns every section of the patient', function () {
        $patient = PatientFixtures::register();

        $this->actingAs(PatientFixtures::actor('view'))->get(route('patients.show', $patient))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('patient.uuid', $patient->uuid)
                ->where('patient.is_disabled', false)
                ->where('patient.disabled', null)
                ->has('patient.profile.contact_information')
                ->has('patient.profile.emergency_contacts', 2)
                ->has('patient.profile.ailments', 2)
                ->where('patient.profile.other_ailments.surgeries', 'Apendicectomia'));
    });

    it('shows when, by whom and why a patient was disabled', function () {
        $patient = PatientFixtures::registerDisabled('Registro duplicado');

        $this->actingAs(PatientFixtures::actor('view'))->get(route('patients.show', $patient))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('patient.is_disabled', true)
                ->where('patient.disabled.reason', 'Registro duplicado')
                ->where('patient.disabled.by', $patient->disabledBy->name)
                ->whereType('patient.disabled.at', 'string'));
    });

    it('lists voided consultations only for viewers allowed to see them', function () {
        $patient = PatientFixtures::register();
        $active = MedicalConsultation::factory()->withoutRegulation()->create(['patient_id' => $patient->id]);
        $voided = MedicalConsultation::factory()->withoutRegulation()->voided()->create(['patient_id' => $patient->id]);

        $this->actingAs(PatientFixtures::actor('view'))->get(route('patients.show', $patient))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('consultations', 1)->where('consultations.0.uuid', $active->uuid));

        $allowed = User::factory()->withPermissions(Permission::PatientsView, Permission::ConsultationsViewVoided)->create();

        $this->actingAs($allowed)->get(route('patients.show', $patient))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('consultations', 2));

        expect($voided->fresh()->trashed())->toBeTrue();
    });

    it('exposes the abilities of an active patient', function () {
        $patient = PatientFixtures::register();
        $user = User::factory()->withPermissions(
            Permission::PatientsView, Permission::PatientsUpdate, Permission::PatientsDisable, Permission::PatientsAudit, Permission::ConsultationsCreate,
        )->create();

        $this->actingAs($user)->get(route('patients.show', $patient))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('can', [
                'update' => true, 'disable' => true, 'enable' => false, 'audit' => true, 'createConsultation' => true,
            ]));
    });

    it('exposes the abilities of a disabled patient, even for super-admin', function (string $kind) {
        $patient = PatientFixtures::registerDisabled();
        $user = $kind === 'super-admin'
            ? User::factory()->superAdmin()->create()
            : User::factory()->withPermissions(
                Permission::PatientsView, Permission::PatientsUpdate, Permission::PatientsDisable, Permission::PatientsAudit, Permission::ConsultationsCreate,
            )->create();

        $this->actingAs($user)->get(route('patients.show', $patient))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('can', [
                'update' => false, 'disable' => false, 'enable' => true, 'audit' => true, 'createConsultation' => false,
            ]));
    })->with(['all permissions', 'super-admin']);
});

describe('dashboard patient search and summary', function () {
    it('lists a disabled patient flagged as disabled and keeps its summary readable', function () {
        $active = PatientFixtures::register(['patient' => ['first_name' => 'Zoraida', 'last_name' => 'Activa']]);
        $disabled = PatientFixtures::registerDisabled();
        $disabled->forceFill(['first_name' => 'Zoraida', 'last_name' => 'Deshabilitada'])->saveQuietly();
        $caller = User::factory()->withPermissions(Permission::PatientsView, Permission::ConsultationsView, Permission::ConsultationsCreate)->create();

        $results = collect($this->actingAs($caller)->getJson(route('dashboard.patients.search', ['query' => 'Zoraida']))->assertOk()->json())->keyBy('uuid');

        expect($results->get($active->uuid)['is_disabled'])->toBeFalse()
            ->and($results->get($disabled->uuid)['is_disabled'])->toBeTrue();

        $this->actingAs($caller)->getJson(route('dashboard.patients.summary', $disabled))
            ->assertOk()
            ->assertJsonPath('patient.is_disabled', true)
            ->assertJsonPath('can.createConsultation', false);

        $this->actingAs($caller)->getJson(route('dashboard.patients.summary', $active))
            ->assertOk()
            ->assertJsonPath('patient.is_disabled', false)
            ->assertJsonPath('can.createConsultation', true);
    });
});
