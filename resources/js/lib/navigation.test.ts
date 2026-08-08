import { describe, expect, it } from 'vitest';
import { NAV_GROUPS, visibleNavGroups } from './navigation';

describe('NAV_GROUPS', () => {
    it('matches the exact structure from the spec: order, labels, hrefs, and permissions', () => {
        const shape = NAV_GROUPS.map((group) => ({
            label: group.label,
            items: group.items.map((item) => ({ title: item.title, href: item.href, permission: item.permission })),
        }));

        expect(shape).toEqual([
            {
                label: undefined,
                items: [{ title: 'Pacientes', href: '#', permission: 'patients.view' }],
            },
            {
                label: 'ATENCIÓN MÉDICA',
                items: [
                    { title: 'Consultas', href: '/dashboard', permission: 'consultations.view' },
                    { title: 'Reportes y estadísticas', href: '#', permission: 'reports.generate' },
                ],
            },
            {
                label: 'ADMINISTRACIÓN GENERAL',
                items: [
                    { title: 'Usuarios', href: '/users', permission: 'users.manage' },
                    { title: 'Roles', href: '/roles', permission: 'roles.manage' },
                ],
            },
        ]);
    });
});

describe('visibleNavGroups', () => {
    it('keeps every group and item, in the exact order, for a full-access user', () => {
        const result = visibleNavGroups(NAV_GROUPS, () => true);

        expect(result.map((group) => group.label)).toEqual([undefined, 'ATENCIÓN MÉDICA', 'ADMINISTRACIÓN GENERAL']);
        expect(result[0].items.map((item) => item.title)).toEqual(['Pacientes']);
        expect(result[1].items.map((item) => item.title)).toEqual(['Consultas', 'Reportes y estadísticas']);
        expect(result[2].items.map((item) => item.title)).toEqual(['Usuarios', 'Roles']);
    });

    it('hides an item the user lacks permission for, keeping the rest of its group', () => {
        const result = visibleNavGroups(NAV_GROUPS, (permission) => permission !== 'roles.manage');

        const adminGroup = result.find((group) => group.label === 'ADMINISTRACIÓN GENERAL');
        expect(adminGroup?.items.map((item) => item.title)).toEqual(['Usuarios']);
    });

    it('drops a group entirely, including its label, when every item in it is hidden', () => {
        const result = visibleNavGroups(
            NAV_GROUPS,
            (permission) => permission !== 'users.manage' && permission !== 'roles.manage',
        );

        expect(result).toHaveLength(2);
        expect(result.some((group) => group.label === 'ADMINISTRACIÓN GENERAL')).toBe(false);
    });
});
