<?php

use App\Enums\Permission;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\Models\Permission as PermissionModel;

it('seeds the seven consultation permissions and no delete permission', function () {
    $this->seed(RolePermissionSeeder::class);

    $names = PermissionModel::query()->where('name', 'like', 'consultations.%')->orderBy('name')->pluck('name')->all();

    expect($names)->toBe([
        'consultations.audit',
        'consultations.create',
        'consultations.update',
        'consultations.view',
        'consultations.view-voided',
        'consultations.void',
        'consultations.void-any',
    ])->and(Permission::values())->not->toContain('consultations.delete');
});
