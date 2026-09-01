<?php

use App\Enums\Permission;
use App\Models\Medication;
use App\Models\User;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

describe('medication demand projection report', function () {
    it('denies access without reports.generate', function () {
        $response = $this->actingAs(User::factory()->create())->get(route('reports.medication-demand'));

        $response->assertForbidden();
    });

    it('renders the report with a null projection when no medication is selected', function () {
        $viewer = User::factory()->withPermissions(Permission::ReportsGenerate)->create();
        Medication::factory()->create();

        $this->actingAs($viewer)->get(route('reports.medication-demand'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('reports/MedicationDemand', false)
                ->where('projection', null)
                ->has('medications', 1));
    });

    it('renders the report with the projection for a selected medication', function () {
        $viewer = User::factory()->withPermissions(Permission::ReportsGenerate)->create();
        $medication = Medication::factory()->create();

        $this->actingAs($viewer)->get(route('reports.medication-demand', ['medication' => $medication->uuid]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('reports/MedicationDemand', false)
                ->where('projection.status', 'insufficient_data')
                ->where('projection.medication.uuid', $medication->uuid)
                ->where('projection.is_indicative', true));
    });

    it('rejects a non-uuid medication query value', function () {
        $viewer = User::factory()->withPermissions(Permission::ReportsGenerate)->create();

        $response = $this->actingAs($viewer)->getJson(route('reports.medication-demand', ['medication' => 'not-a-uuid']));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['medication']);
    });

    it('rejects a well-formed uuid that does not match any medication', function () {
        $viewer = User::factory()->withPermissions(Permission::ReportsGenerate)->create();

        $response = $this->actingAs($viewer)->getJson(route('reports.medication-demand', ['medication' => (string) Str::uuid()]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['medication']);
    });
});

describe('guest access', function () {
    it('redirects a guest to login instead of returning 403', function () {
        $this->get(route('reports.medication-demand'))->assertRedirect(route('auth.login'));
    });
});
