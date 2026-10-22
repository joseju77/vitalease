import { describe, expect, it } from 'vitest';
import {
    grantPermission,
    permissionDependents,
    permissionRequirementHint,
    permissionRequirements,
    revokePermission,
    type PermissionDependencyMap,
} from './permissionDependencies';

const catalog = [
    'users.manage',
    'patients.view',
    'patients.create',
    'consultations.view',
    'consultations.create',
    'consultations.void',
    'consultations.void-any',
];

const dependencies: PermissionDependencyMap = {
    'users.manage': [],
    'patients.view': [],
    'patients.create': ['patients.view'],
    'consultations.view': ['patients.view'],
    'consultations.create': ['consultations.view'],
    'consultations.void': ['consultations.view'],
    'consultations.void-any': ['consultations.void'],
};

describe('permissionRequirements', () => {
    it('returns the transitive requirements, nearest first', () => {
        expect(permissionRequirements('consultations.void-any', dependencies)).toEqual([
            'consultations.void',
            'consultations.view',
            'patients.view',
        ]);
    });

    it('returns an empty list for a permission without requirements or outside the map', () => {
        expect(permissionRequirements('users.manage', dependencies)).toEqual([]);
        expect(permissionRequirements('unknown.permission', dependencies)).toEqual([]);
    });
});

describe('permissionDependents', () => {
    it('returns every permission that transitively depends on the given one', () => {
        expect(permissionDependents('consultations.view', dependencies)).toEqual([
            'consultations.create',
            'consultations.void',
            'consultations.void-any',
        ]);
    });
});

describe('grantPermission', () => {
    it('adds the permission and its transitive requirements', () => {
        expect(grantPermission([], 'consultations.void-any', { catalog, dependencies })).toEqual([
            'patients.view',
            'consultations.view',
            'consultations.void',
            'consultations.void-any',
        ]);
    });

    it('keeps the result in catalog order regardless of the selection order', () => {
        expect(
            grantPermission(
                ['consultations.create', 'users.manage', 'patients.view', 'consultations.view'],
                'patients.create',
                {
                    catalog,
                    dependencies,
                },
            ),
        ).toEqual(['users.manage', 'patients.view', 'patients.create', 'consultations.view', 'consultations.create']);
    });

    it('does not add a requirement already satisfied by another source', () => {
        expect(
            grantPermission([], 'consultations.create', {
                catalog,
                dependencies,
                satisfied: ['patients.view', 'consultations.view'],
            }),
        ).toEqual(['consultations.create']);
    });

    it('still adds a deeper requirement the other source does not satisfy', () => {
        expect(
            grantPermission([], 'consultations.create', { catalog, dependencies, satisfied: ['consultations.view'] }),
        ).toEqual(['patients.view', 'consultations.create']);
    });
});

describe('revokePermission', () => {
    it('removes the permission and every permission that transitively depends on it', () => {
        expect(
            revokePermission(
                [
                    'users.manage',
                    'patients.view',
                    'patients.create',
                    'consultations.view',
                    'consultations.void',
                    'consultations.void-any',
                ],
                'patients.view',
                { catalog, dependencies },
            ),
        ).toEqual(['users.manage']);
    });

    it('leaves unrelated and upstream permissions selected', () => {
        expect(
            revokePermission(
                ['patients.view', 'consultations.view', 'consultations.void', 'consultations.void-any'],
                'consultations.void',
                {
                    catalog,
                    dependencies,
                },
            ),
        ).toEqual(['patients.view', 'consultations.view']);
    });

    it('keeps dependents whose requirement is still satisfied by another source', () => {
        expect(
            revokePermission(['patients.view', 'patients.create', 'consultations.view'], 'patients.view', {
                catalog,
                dependencies,
                satisfied: ['patients.view'],
            }),
        ).toEqual(['patients.create', 'consultations.view']);
    });

    it('keeps the result in catalog order', () => {
        expect(
            revokePermission(
                ['consultations.view', 'users.manage', 'patients.view', 'consultations.create'],
                'consultations.create',
                {
                    catalog,
                    dependencies,
                },
            ),
        ).toEqual(['users.manage', 'patients.view', 'consultations.view']);
    });
});

describe('permissionRequirementHint', () => {
    it('lists the direct requirements with their standalone labels', () => {
        expect(permissionRequirementHint('consultations.void-any', dependencies)).toBe('Requiere: Consultas: Anular');
    });

    it('marks a requirement granted by the role', () => {
        expect(permissionRequirementHint('patients.create', dependencies, ['patients.view'])).toBe(
            'Requiere: Pacientes: Ver (incluido en el rol)',
        );
    });

    it('returns null for a permission without requirements', () => {
        expect(permissionRequirementHint('patients.view', dependencies)).toBeNull();
    });
});
