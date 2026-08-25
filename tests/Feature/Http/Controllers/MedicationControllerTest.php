<?php

use App\Enums\Permission;
use App\Models\InventoryMovement;
use App\Models\MedicalConsultation;
use App\Models\Medication;
use App\Models\User;
use App\Services\Inventory\TreatmentDispensation;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Dispense one treatment line directly through {@see TreatmentDispensation},
 * bypassing HTTP, so a medication ends up with real ledger history.
 */
function dispenseOneLine(MedicalConsultation $consultation, Medication $medication, int $quantity, User $actor): void
{
    DB::transaction(fn () => app(TreatmentDispensation::class)->sync(
        $consultation,
        [[
            'medication_uuid' => $medication->uuid,
            'quantity_dispensed' => $quantity,
            'dose' => '500 mg',
            'frequency' => 'Cada 8 horas',
            'duration' => '5 días',
        ]],
        $actor,
    ));
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function validMedicationPayload(array $overrides = []): array
{
    return array_replace([
        'name' => 'Medication '.fake()->unique()->word(),
        'presentation' => 'Tableta',
        'concentration' => '500 mg',
        'dispensing_unit' => 'unidad',
        'minimum_stock' => 5,
        'is_active' => true,
    ], $overrides);
}

describe('listing medications', function () {
    it('denies listing without inventory.view', function () {
        $this->actingAs(User::factory()->create())->get(route('inventory.index'))->assertForbidden();
    });

    it('lists medications ordered by name through the inventory/Index component', function () {
        $viewer = User::factory()->withPermissions(Permission::InventoryView)->create();
        Medication::factory()->create(['name' => 'Zeta']);
        Medication::factory()->create(['name' => 'Alfa']);

        $this->actingAs($viewer)->get(route('inventory.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('inventory/Index', false)
                ->has('medications.data', 2)
                ->where('medications.data.0.name', 'Alfa')
                ->where('medications.data.1.name', 'Zeta'));
    });

    it('filters the plain listing to low-stock medications via whereColumn', function () {
        $viewer = User::factory()->withPermissions(Permission::InventoryView)->create();
        $low = Medication::factory()->create(['current_stock' => 2, 'minimum_stock' => 5]);
        Medication::factory()->create(['current_stock' => 10, 'minimum_stock' => 5]);

        $this->actingAs($viewer)->get(route('inventory.index', ['low_stock' => true]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('inventory/Index', false)
                ->has('medications.data', 1)
                ->where('medications.data.0.uuid', $low->uuid)
                ->where('filters.low_stock', true));
    });

    it('searches medications by name through the Scout collection driver', function () {
        $viewer = User::factory()->withPermissions(Permission::InventoryView)->create();
        $target = Medication::factory()->create(['name' => 'Paracetamol']);
        Medication::factory()->create(['name' => 'Ibuprofeno']);

        $this->actingAs($viewer)->get(route('inventory.index', ['q' => 'Paracetamol']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('inventory/Index', false)
                ->has('medications.data', 1)
                ->where('medications.data.0.uuid', $target->uuid)
                ->where('filters.q', 'Paracetamol'));
    });
});

describe('computing can_be_deleted', function () {
    it('marks an untouched medication as deletable on the plain index', function () {
        $viewer = User::factory()->withPermissions(Permission::InventoryView)->create();
        Medication::factory()->create();

        $this->actingAs($viewer)->get(route('inventory.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('inventory/Index', false)
                ->where('medications.data.0.can_be_deleted', true));
    });

    it('marks a medication with a movement as not deletable on the plain index', function () {
        $viewer = User::factory()->withPermissions(Permission::InventoryView)->create();
        $medication = Medication::factory()->create();
        InventoryMovement::factory()->for($medication)->create();

        $this->actingAs($viewer)->get(route('inventory.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('inventory/Index', false)
                ->where('medications.data.0.can_be_deleted', false));
    });

    it('marks a medication with a treatment line as not deletable on the plain index', function () {
        $viewer = User::factory()->withPermissions(Permission::InventoryView)->create();
        $physician = User::factory()->create();
        $medication = Medication::factory()->create(['current_stock' => 10]);
        $consultation = MedicalConsultation::factory()->withoutRegulation()->create();
        dispenseOneLine($consultation, $medication, 2, $physician);

        $this->actingAs($viewer)->get(route('inventory.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('inventory/Index', false)
                ->where('medications.data.0.can_be_deleted', false));
    });

    it('exposes can_be_deleted through the Scout collection search path', function () {
        $viewer = User::factory()->withPermissions(Permission::InventoryView)->create();
        $target = Medication::factory()->create(['name' => 'Paracetamol']);
        InventoryMovement::factory()->for($target)->create();

        $this->actingAs($viewer)->get(route('inventory.index', ['q' => 'Paracetamol']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('inventory/Index', false)
                ->where('medications.data.0.can_be_deleted', false));
    });

    it('exposes can_be_deleted on the show page', function () {
        $viewer = User::factory()->withPermissions(Permission::InventoryView)->create();
        $medication = Medication::factory()->create();

        $this->actingAs($viewer)->get(route('inventory.show', $medication))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('inventory/Show', false)
                ->where('medication.can_be_deleted', true));
    });

    it('does not add an extra query per row when computing can_be_deleted', function () {
        $viewer = User::factory()->withPermissions(Permission::InventoryView)->create();
        Medication::factory()->create();

        DB::enableQueryLog();
        $this->actingAs($viewer)->get(route('inventory.index'))->assertOk();
        $singleRowQueryCount = count(DB::getQueryLog());
        DB::flushQueryLog();

        Medication::factory()->count(4)->create();

        $this->actingAs($viewer)->get(route('inventory.index'))->assertOk();
        $multiRowQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect($multiRowQueryCount)->toBe($singleRowQueryCount);
    });
});

describe('viewing a medication', function () {
    it('denies viewing without inventory.view', function () {
        $medication = Medication::factory()->create();

        $response = $this->actingAs(User::factory()->create())->get(route('inventory.show', $medication));

        $response->assertForbidden();
    });

    it('shows the medication detail with its paginated movement history for a user with inventory.view', function () {
        $viewer = User::factory()->withPermissions(Permission::InventoryView)->create();
        $medication = Medication::factory()->create();
        InventoryMovement::factory()->for($medication)->create();

        $this->actingAs($viewer)->get(route('inventory.show', $medication))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('inventory/Show', false)
                ->where('medication.uuid', $medication->uuid)
                ->has('movements.data', 1));
    });
});

describe('creating a medication', function () {
    it('denies creating without inventory.create', function () {
        $response = $this->actingAs(User::factory()->create())->post(route('inventory.store'), validMedicationPayload());

        $response->assertForbidden();
        expect(Medication::query()->count())->toBe(0);
    });

    it('creates a medication with zero stock for a user with inventory.create', function () {
        $creator = User::factory()->withPermissions(Permission::InventoryCreate)->create();

        $response = $this->actingAs($creator)->post(route('inventory.store'), validMedicationPayload(['name' => 'Amoxicilina']));

        $response->assertRedirect(route('inventory.index'));
        $medication = Medication::query()->sole();
        expect($medication->name)->toBe('Amoxicilina')
            ->and($medication->current_stock)->toBe(0);
    });

    it('rejects current_stock as an unknown field', function () {
        $creator = User::factory()->withPermissions(Permission::InventoryCreate)->create();

        $response = $this->actingAs($creator)->post(
            route('inventory.store'),
            validMedicationPayload(['current_stock' => 100])
        );

        $response->assertSessionHasErrors(['current_stock']);
        expect(Medication::query()->count())->toBe(0);
    });

    it('rejects a duplicate name, presentation, and concentration combination', function () {
        $creator = User::factory()->withPermissions(Permission::InventoryCreate)->create();
        Medication::factory()->create(['name' => 'Paracetamol', 'presentation' => 'Tableta', 'concentration' => '500 mg']);

        $response = $this->actingAs($creator)->postJson(
            route('inventory.store'),
            validMedicationPayload(['name' => 'Paracetamol', 'presentation' => 'Tableta', 'concentration' => '500 mg'])
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
    });
});

describe('updating a medication', function () {
    it('denies updating without inventory.update', function () {
        $medication = Medication::factory()->create();

        $response = $this->actingAs(User::factory()->create())->patch(route('inventory.update', $medication), validMedicationPayload());

        $response->assertForbidden();
    });

    it("updates the target medication's catalog data for a user with inventory.update", function () {
        $updater = User::factory()->withPermissions(Permission::InventoryUpdate)->create();
        $medication = Medication::factory()->create(['dispensing_unit' => 'unidad']);

        $response = $this->actingAs($updater)->patch(
            route('inventory.update', $medication),
            validMedicationPayload(['name' => $medication->name, 'presentation' => $medication->presentation, 'concentration' => $medication->concentration, 'dispensing_unit' => 'ml'])
        );

        $response->assertRedirect();
        expect($medication->fresh()->dispensing_unit)->toBe('ml');
    });

    it('ignores the current medication when checking catalog uniqueness on update', function () {
        $updater = User::factory()->withPermissions(Permission::InventoryUpdate)->create();
        $medication = Medication::factory()->create(['name' => 'Paracetamol', 'presentation' => 'Tableta', 'concentration' => '500 mg']);

        $response = $this->actingAs($updater)->patch(
            route('inventory.update', $medication),
            validMedicationPayload(['name' => 'Paracetamol', 'presentation' => 'Tableta', 'concentration' => '500 mg'])
        );

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
    });

    it("rejects renaming a medication to another medication's existing name, presentation, and concentration", function () {
        $updater = User::factory()->withPermissions(Permission::InventoryUpdate)->create();
        Medication::factory()->create(['name' => 'Paracetamol', 'presentation' => 'Tableta', 'concentration' => '500 mg']);
        $medication = Medication::factory()->create(['name' => 'Ibuprofeno', 'presentation' => 'Tableta', 'concentration' => '500 mg']);

        $response = $this->actingAs($updater)->patchJson(
            route('inventory.update', $medication),
            validMedicationPayload(['name' => 'Paracetamol', 'presentation' => 'Tableta', 'concentration' => '500 mg'])
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
    });

    it('rejects current_stock as an unknown field on update', function () {
        $updater = User::factory()->withPermissions(Permission::InventoryUpdate)->create();
        $medication = Medication::factory()->create(['current_stock' => 10]);

        $response = $this->actingAs($updater)->patch(
            route('inventory.update', $medication),
            validMedicationPayload(['name' => $medication->name, 'presentation' => $medication->presentation, 'concentration' => $medication->concentration, 'current_stock' => 999])
        );

        $response->assertSessionHasErrors(['current_stock']);
        expect($medication->fresh()->current_stock)->toBe(10);
    });
});

describe('activating and deactivating a medication', function () {
    it('denies activation and deactivation without inventory.update', function () {
        $medication = Medication::factory()->inactive()->create();
        $caller = User::factory()->create();

        $this->actingAs($caller)->patch(route('inventory.activate', $medication))->assertForbidden();
        $this->actingAs($caller)->patch(route('inventory.deactivate', $medication))->assertForbidden();
    });

    it('activates and deactivates a medication without touching its stock', function () {
        $updater = User::factory()->withPermissions(Permission::InventoryUpdate)->create();
        $medication = Medication::factory()->create(['current_stock' => 7, 'is_active' => true]);

        $this->actingAs($updater)->patch(route('inventory.deactivate', $medication))->assertRedirect();
        expect($medication->fresh()->is_active)->toBeFalse()
            ->and($medication->fresh()->current_stock)->toBe(7);

        $this->actingAs($updater)->patch(route('inventory.activate', $medication))->assertRedirect();
        expect($medication->fresh()->is_active)->toBeTrue()
            ->and($medication->fresh()->current_stock)->toBe(7);
    });
});

describe('deleting a medication', function () {
    it('denies deleting without inventory.delete', function () {
        $medication = Medication::factory()->create();

        $response = $this->actingAs(User::factory()->create())->delete(route('inventory.destroy', $medication));

        $response->assertForbidden();
        expect(Medication::query()->count())->toBe(1);
    });

    it('hard-deletes a medication with no history', function () {
        $deleter = User::factory()->withPermissions(Permission::InventoryDelete)->create();
        $medication = Medication::factory()->create();

        $response = $this->actingAs($deleter)->delete(route('inventory.destroy', $medication));

        $response->assertRedirect(route('inventory.index'));
        expect(Medication::query()->count())->toBe(0);
    });

    it('rejects deletion with a 422 when the medication has movements', function () {
        $deleter = User::factory()->withPermissions(Permission::InventoryDelete, Permission::InventoryCreate)->create();
        $medication = Medication::factory()->create();
        InventoryMovement::factory()->for($medication)->create();

        $response = $this->actingAs($deleter)->deleteJson(route('inventory.destroy', $medication));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['medication']);
        expect(Medication::query()->count())->toBe(1);
    });

    it('rejects deletion with a 422 when the medication has treatment lines', function () {
        $deleter = User::factory()->withPermissions(Permission::InventoryDelete)->create();
        $physician = User::factory()->create();
        $medication = Medication::factory()->create(['current_stock' => 10]);
        $consultation = MedicalConsultation::factory()->withoutRegulation()->create();
        dispenseOneLine($consultation, $medication, 2, $physician);

        $response = $this->actingAs($deleter)->deleteJson(route('inventory.destroy', $medication));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['medication']);
        expect(Medication::query()->count())->toBe(1);
    });
});

describe('guest access', function () {
    it('redirects a guest to login instead of returning 403', function () {
        $medication = Medication::factory()->create();

        $this->get(route('inventory.index'))->assertRedirect(route('auth.login'));
        $this->get(route('inventory.show', $medication))->assertRedirect(route('auth.login'));
    });
});
