<?php

use App\Enums\Permission;

describe('requires', function () {
    it('declares the direct dependencies of every dependent permission', function (Permission $permission, array $expected) {
        expect($permission->requires())->toBe($expected);
    })->with([
        'patients.create' => [Permission::PatientsCreate, [Permission::PatientsView]],
        'patients.update' => [Permission::PatientsUpdate, [Permission::PatientsView]],
        'patients.disable' => [Permission::PatientsDisable, [Permission::PatientsView]],
        'patients.audit' => [Permission::PatientsAudit, [Permission::PatientsView]],
        'consultations.view' => [Permission::ConsultationsView, [Permission::PatientsView]],
        'consultations.create' => [Permission::ConsultationsCreate, [Permission::ConsultationsView]],
        'consultations.update' => [Permission::ConsultationsUpdate, [Permission::ConsultationsView]],
        'consultations.void' => [Permission::ConsultationsVoid, [Permission::ConsultationsView]],
        'consultations.audit' => [Permission::ConsultationsAudit, [Permission::ConsultationsView]],
        'consultations.view-voided' => [Permission::ConsultationsViewVoided, [Permission::ConsultationsView]],
        'consultations.void-any' => [Permission::ConsultationsVoidAny, [Permission::ConsultationsVoid]],
        'inventory.create' => [Permission::InventoryCreate, [Permission::InventoryView]],
        'inventory.update' => [Permission::InventoryUpdate, [Permission::InventoryView]],
        'inventory.delete' => [Permission::InventoryDelete, [Permission::InventoryView]],
    ]);

    it('declares no dependency for the independent permissions', function (Permission $permission) {
        expect($permission->requires())->toBe([]);
    })->with([
        Permission::UsersManage,
        Permission::RolesManage,
        Permission::ReportsGenerate,
        Permission::PatientsView,
        Permission::InventoryView,
    ]);
});

describe('allRequirements', function () {
    it('resolves the transitive closure from the nearest dependency outwards', function () {
        expect(Permission::ConsultationsVoidAny->allRequirements())->toBe([
            Permission::ConsultationsVoid,
            Permission::ConsultationsView,
            Permission::PatientsView,
        ]);
    });

    it('returns no requirement for an independent permission', function () {
        expect(Permission::UsersManage->allRequirements())->toBe([]);
    });
});

describe('dependencyMap', function () {
    it('maps every catalog value to the values it directly requires', function () {
        $map = Permission::dependencyMap();

        expect(array_keys($map))->toBe(Permission::values())
            ->and($map['consultations.void-any'])->toBe(['consultations.void'])
            ->and($map['consultations.view'])->toBe(['patients.view'])
            ->and($map['users.manage'])->toBe([]);
    });
});

describe('missingDependencies', function () {
    it('reports every transitive requirement missing from a lone dependent permission', function () {
        expect(Permission::missingDependencies([Permission::ConsultationsVoidAny]))->toBe([
            'consultations.void-any' => [
                Permission::ConsultationsVoid,
                Permission::ConsultationsView,
                Permission::PatientsView,
            ],
        ]);
    });

    it('reports only the requirements absent from the granted set', function () {
        $missing = Permission::missingDependencies([
            Permission::ConsultationsCreate,
            Permission::ConsultationsView,
        ]);

        expect($missing)->toBe([
            'consultations.create' => [Permission::PatientsView],
            'consultations.view' => [Permission::PatientsView],
        ]);
    });

    it('reports nothing for a set that satisfies every dependency', function () {
        expect(Permission::missingDependencies(Permission::cases()))->toBe([])
            ->and(Permission::missingDependencies([]))->toBe([]);
    });
});
