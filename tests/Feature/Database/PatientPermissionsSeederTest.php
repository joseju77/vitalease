<?php

use App\Enums\Permission;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\Models\Permission as PermissionModel;

it('seeds the five patient permissions and no delete permission', function () {
    $this->seed(RolePermissionSeeder::class);

    $names = PermissionModel::query()->where('name', 'like', 'patients.%')->orderBy('name')->pluck('name')->all();

    expect($names)->toBe([
        'patients.audit',
        'patients.create',
        'patients.disable',
        'patients.update',
        'patients.view',
    ])->and(Permission::values())->not->toContain('patients.delete');
});
