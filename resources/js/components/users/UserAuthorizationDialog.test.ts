import { router } from '@inertiajs/core';
import { DOMWrapper, mount, VueWrapper } from '@vue/test-utils';
import { nextTick } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { updateAuthorization } from '@/actions/App/Http/Controllers/UserController';

import UserAuthorizationDialog from './UserAuthorizationDialog.vue';

/**
 * `UserAuthorizationDialog.vue` uses Inertia's REAL `useForm`; submits are
 * intercepted at `@inertiajs/core`'s `router`, matching
 * `MedicationFormDialog.test.ts`. `DialogContent` teleports to
 * `document.body`, so every query goes through a `DOMWrapper` over it.
 */
type VisitOptions = {
    onError?: (errors: Record<string, string>) => void;
};

const permissionCatalog = ['patients.view', 'patients.create', 'consultations.view', 'consultations.create'];

const permissionDependencies = {
    'patients.view': [],
    'patients.create': ['patients.view'],
    'consultations.view': ['patients.view'],
    'consultations.create': ['consultations.view'],
};

const rolePermissions = {
    reception: ['patients.view'],
};

function body(): DOMWrapper<HTMLElement> {
    return new DOMWrapper(document.body);
}

function checkbox(permission: string): Omit<DOMWrapper<HTMLElement>, 'exists'> {
    return body().get(`[id="permission-${permission}"]`);
}

function isChecked(permission: string): boolean {
    return checkbox(permission).attributes('data-state') === 'checked';
}

function fieldText(permission: string): string {
    return checkbox(permission).element.closest('[data-slot="field"]')?.textContent ?? '';
}

async function toggle(permission: string) {
    await checkbox(permission).trigger('click');
    await nextTick();
}

async function submit() {
    await body().get('form').trigger('submit');
}

let wrapper: VueWrapper | null = null;

async function mountDialog(user: { roles?: string[]; permissions?: string[] } = {}): Promise<VueWrapper> {
    const mounted = mount(UserAuthorizationDialog, {
        props: {
            open: true,
            user: { uuid: 'user-1', name: 'Ana', roles: user.roles ?? [], permissions: user.permissions ?? [] },
            roleCatalog: ['reception'],
            permissionCatalog,
            permissionDependencies,
            rolePermissions,
        },
    });
    await nextTick();
    return mounted;
}

describe('UserAuthorizationDialog.vue', () => {
    let putSpy: ReturnType<typeof vi.spyOn>;

    beforeEach(() => {
        putSpy = vi.spyOn(router, 'put').mockImplementation(() => {});
    });

    afterEach(() => {
        wrapper?.unmount();
        wrapper = null;
        document.body.innerHTML = '';
        vi.restoreAllMocks();
    });

    it('checks the transitive requirements when a direct permission is checked', async () => {
        wrapper = await mountDialog();

        await toggle('consultations.create');

        expect(isChecked('patients.view')).toBe(true);
        expect(isChecked('consultations.view')).toBe(true);
        expect(isChecked('consultations.create')).toBe(true);
    });

    it('unchecks every dependent direct permission when a requirement is unchecked', async () => {
        wrapper = await mountDialog({ permissions: [...permissionCatalog] });

        await toggle('consultations.view');

        expect(isChecked('consultations.create')).toBe(false);
        expect(isChecked('consultations.view')).toBe(false);
        expect(isChecked('patients.view')).toBe(true);
        expect(isChecked('patients.create')).toBe(true);
    });

    it('does not force-add a requirement the selected role already grants', async () => {
        wrapper = await mountDialog({ roles: ['reception'] });

        await toggle('consultations.create');
        await submit();

        expect(isChecked('patients.view')).toBe(false);
        const [url, payload] = putSpy.mock.calls[0] as [string, Record<string, unknown>];
        expect(url).toBe(updateAuthorization('user-1').url);
        expect(payload).toEqual({
            roles: ['reception'],
            permissions: ['consultations.view', 'consultations.create'],
        });
    });

    it('keeps dependents checked when an unchecked requirement is still granted by the role', async () => {
        wrapper = await mountDialog({ roles: ['reception'], permissions: ['patients.view', 'patients.create'] });

        await toggle('patients.view');

        expect(isChecked('patients.view')).toBe(false);
        expect(isChecked('patients.create')).toBe(true);
    });

    it('shows the direct requirements, marking the ones the selected role grants', async () => {
        wrapper = await mountDialog({ roles: ['reception'] });

        expect(fieldText('patients.create')).toContain('Requiere: Pacientes: Ver (incluido en el rol)');
        expect(fieldText('consultations.create')).toContain('Requiere: Consultas: Ver');
        expect(fieldText('consultations.create')).not.toContain('incluido en el rol');
        expect(fieldText('patients.view')).not.toContain('Requiere');
    });

    it('shows the server-side permission dependency error after a failed submit', async () => {
        putSpy.mockImplementation((_url: string, _data: unknown, options: VisitOptions) => {
            options.onError?.({ permissions: '«Consultas: Ver» requiere «Pacientes: Ver».' });
        });
        wrapper = await mountDialog();

        await submit();
        await nextTick();

        expect(body().get('[data-slot="field-error"]').text()).toBe('«Consultas: Ver» requiere «Pacientes: Ver».');
    });
});
