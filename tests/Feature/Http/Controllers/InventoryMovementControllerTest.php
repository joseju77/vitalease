<?php

use App\Enums\InventoryMovementType;
use App\Enums\Permission;
use App\Models\InventoryMovement;
use App\Models\Medication;
use App\Models\User;

describe('recording an Entry', function () {
    it('denies recording an Entry without inventory.create', function () {
        $medication = Medication::factory()->create(['current_stock' => 5]);

        $response = $this->actingAs(User::factory()->create())->post(
            route('inventory.entries.store', $medication),
            ['quantity' => 10]
        );

        $response->assertForbidden();
        expect($medication->fresh()->current_stock)->toBe(5);
    });

    it('increases stock and records one Entry movement for a user with inventory.create', function () {
        $creator = User::factory()->withPermissions(Permission::InventoryCreate)->create();
        $medication = Medication::factory()->create(['current_stock' => 5]);

        $response = $this->actingAs($creator)->post(
            route('inventory.entries.store', $medication),
            ['quantity' => 10, 'notes' => 'Purchase order 42']
        );

        $response->assertRedirect();
        expect($medication->fresh()->current_stock)->toBe(15);

        $movement = InventoryMovement::query()->sole();
        expect($movement->type)->toBe(InventoryMovementType::Entry)
            ->and($movement->quantity)->toBe(10)
            ->and($movement->stock_after)->toBe(15);
    });

    it('rejects an Entry quantity that is not strictly positive', function () {
        $creator = User::factory()->withPermissions(Permission::InventoryCreate)->create();
        $medication = Medication::factory()->create(['current_stock' => 5]);

        $response = $this->actingAs($creator)->postJson(route('inventory.entries.store', $medication), ['quantity' => 0]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['quantity']);
        expect($medication->fresh()->current_stock)->toBe(5);
    });
});

describe('recording an Adjustment', function () {
    it('denies recording an Adjustment without inventory.update', function () {
        $medication = Medication::factory()->create(['current_stock' => 5]);

        $response = $this->actingAs(User::factory()->create())->post(
            route('inventory.adjustments.store', $medication),
            ['quantity' => -2, 'notes' => 'Damaged units']
        );

        $response->assertForbidden();
        expect($medication->fresh()->current_stock)->toBe(5);
    });

    it('applies a positive or negative Adjustment for a user with inventory.update', function () {
        $updater = User::factory()->withPermissions(Permission::InventoryUpdate)->create();
        $medication = Medication::factory()->create(['current_stock' => 10]);

        $this->actingAs($updater)->post(route('inventory.adjustments.store', $medication), ['quantity' => -3, 'notes' => 'Damaged units'])
            ->assertRedirect();
        expect($medication->fresh()->current_stock)->toBe(7);

        $this->actingAs($updater)->post(route('inventory.adjustments.store', $medication), ['quantity' => 5, 'notes' => 'Recount'])
            ->assertRedirect();
        expect($medication->fresh()->current_stock)->toBe(12);
    });

    it('rejects a zero-quantity Adjustment', function () {
        $updater = User::factory()->withPermissions(Permission::InventoryUpdate)->create();
        $medication = Medication::factory()->create(['current_stock' => 10]);

        $response = $this->actingAs($updater)->postJson(
            route('inventory.adjustments.store', $medication),
            ['quantity' => 0, 'notes' => 'no-op']
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['quantity']);
    });

    it('rejects an Adjustment with blank notes', function () {
        $updater = User::factory()->withPermissions(Permission::InventoryUpdate)->create();
        $medication = Medication::factory()->create(['current_stock' => 10]);

        $response = $this->actingAs($updater)->postJson(
            route('inventory.adjustments.store', $medication),
            ['quantity' => 2, 'notes' => '   ']
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['notes']);
    });

    it('rejects an over-adjustment on the quantity field with a 422 and leaves stock unchanged', function () {
        $updater = User::factory()->withPermissions(Permission::InventoryUpdate)->create();
        $medication = Medication::factory()->create(['current_stock' => 3]);

        $response = $this->actingAs($updater)->postJson(
            route('inventory.adjustments.store', $medication),
            ['quantity' => -10, 'notes' => 'Too much shrinkage']
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['quantity']);
        expect($medication->fresh()->current_stock)->toBe(3)
            ->and(InventoryMovement::query()->count())->toBe(0);
    });
});

describe('guest access', function () {
    it('redirects a guest to login instead of returning 403', function () {
        $medication = Medication::factory()->create();

        $this->post(route('inventory.entries.store', $medication), ['quantity' => 1])
            ->assertRedirect(route('auth.login'));
        $this->post(route('inventory.adjustments.store', $medication), ['quantity' => 1, 'notes' => 'note'])
            ->assertRedirect(route('auth.login'));
    });
});
