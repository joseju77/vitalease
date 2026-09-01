<?php

use App\Models\InventoryMovement;
use App\Models\Medication;
use App\Models\User;

describe('append-only guard', function () {
    it('refuses to update an existing movement', function () {
        $movement = InventoryMovement::factory()->create();

        expect(fn () => $movement->update(['notes' => 'changed']))->toThrow(LogicException::class);
    });

    it('refuses to delete an existing movement', function () {
        $movement = InventoryMovement::factory()->create();

        expect(fn () => $movement->delete())->toThrow(LogicException::class);
    });

    it('does not persist a blocked update', function () {
        $movement = InventoryMovement::factory()->create(['notes' => null]);

        try {
            $movement->update(['notes' => 'changed']);
        } catch (LogicException) {
            // expected
        }

        expect($movement->fresh()->notes)->toBeNull();
    });
});

describe('relationships', function () {
    it('resolves the medication and user relations', function () {
        $medication = Medication::factory()->create();
        $user = User::factory()->create();
        $movement = InventoryMovement::factory()->for($medication)->for($user)->create();

        expect($movement->medication->is($medication))->toBeTrue()
            ->and($movement->user->is($user))->toBeTrue();
    });
});
