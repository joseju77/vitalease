import { describe, expect, it } from 'vitest';
import { formatPermissionLabel, groupPermissionsByResource } from './permissionLabels';

describe('formatPermissionLabel', () => {
    it('formats a "manage" action as "<Verb> <resource>"', () => {
        expect(formatPermissionLabel('users.manage')).toBe('Gestionar usuarios');
    });

    it('formats a "generate" action as "<Verb> <resource>"', () => {
        expect(formatPermissionLabel('reports.generate')).toBe('Generar reportes');
    });

    it('formats a plain CRUD action as "<Resource>: <Verb>"', () => {
        expect(formatPermissionLabel('patients.view')).toBe('Pacientes: Ver');
    });

    it('formats another CRUD action as "<Resource>: <Verb>"', () => {
        expect(formatPermissionLabel('consultations.delete')).toBe('Consultas: Eliminar');
    });
});

describe('groupPermissionsByResource', () => {
    it('groups permissions by resource prefix, preserving catalog order', () => {
        const catalog = ['users.manage', 'patients.view', 'patients.create', 'roles.manage'];

        const groups = groupPermissionsByResource(catalog);

        expect(groups).toEqual([
            { resource: 'users', label: 'Usuarios', permissions: ['users.manage'] },
            { resource: 'patients', label: 'Pacientes', permissions: ['patients.view', 'patients.create'] },
            { resource: 'roles', label: 'Roles', permissions: ['roles.manage'] },
        ]);
    });
});
