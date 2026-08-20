<?php

use App\Enums\Permission;
use App\Models\Medication;
use App\Models\User;

dataset('medicationPolicyAbilities', [
    'viewAny requires inventory.view' => ['viewAny', Permission::InventoryView],
    'view requires inventory.view' => ['view', Permission::InventoryView],
    'create requires inventory.create' => ['create', Permission::InventoryCreate],
    'update requires inventory.update' => ['update', Permission::InventoryUpdate],
    'delete requires inventory.delete' => ['delete', Permission::InventoryDelete],
    'recordEntry requires inventory.create' => ['recordEntry', Permission::InventoryCreate],
    'recordAdjustment requires inventory.update' => ['recordAdjustment', Permission::InventoryUpdate],
]);

describe('permission matrix', function () {
    it('grants the ability only to a user holding the exact required permission', function (string $ability, Permission $permission) {
        $medication = Medication::factory()->create();
        $holder = User::factory()->withPermissions($permission)->create();
        $stranger = User::factory()->create();

        expect($holder->can($ability, $medication))->toBeTrue()
            ->and($stranger->can($ability, $medication))->toBeFalse();
    })->with('medicationPolicyAbilities');

    it('denies every ability to a user with unrelated permissions', function (string $ability) {
        $medication = Medication::factory()->create();
        $caller = User::factory()->withPermissions(Permission::PatientsView)->create();

        expect($caller->can($ability, $medication))->toBeFalse();
    })->with([
        'viewAny' => ['viewAny'],
        'view' => ['view'],
        'create' => ['create'],
        'update' => ['update'],
        'delete' => ['delete'],
        'recordEntry' => ['recordEntry'],
        'recordAdjustment' => ['recordAdjustment'],
    ]);

    it('allows a super-admin every ability with zero direct permissions', function (string $ability) {
        $medication = Medication::factory()->create();
        $admin = User::factory()->superAdmin()->create();

        expect($admin->can($ability, $medication))->toBeTrue();
    })->with([
        'viewAny' => ['viewAny'],
        'view' => ['view'],
        'create' => ['create'],
        'update' => ['update'],
        'delete' => ['delete'],
        'recordEntry' => ['recordEntry'],
        'recordAdjustment' => ['recordAdjustment'],
    ]);
});
