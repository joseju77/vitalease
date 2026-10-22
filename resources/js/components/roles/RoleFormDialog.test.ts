import { router } from '@inertiajs/core';
import { DOMWrapper, mount, VueWrapper } from '@vue/test-utils';
import { nextTick } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { store } from '@/actions/App/Http/Controllers/RoleController';

import RoleFormDialog from './RoleFormDialog.vue';

/**
 * `RoleFormDialog.vue` uses Inertia's REAL `useForm`; submits are intercepted
 * at `@inertiajs/core`'s `router`, matching `MedicationFormDialog.test.ts`.
 * `DialogContent` teleports to `document.body`, so every query goes through a
 * `DOMWrapper` over `document.body`.
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

function body(): DOMWrapper<HTMLElement> {
    return new DOMWrapper(document.body);
}

function checkbox(permission: string): Omit<DOMWrapper<HTMLElement>, 'exists'> {
    return body().get(`[id="role-permission-${permission}"]`);
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

async function mountDialog(props: Record<string, unknown> = {}): Promise<VueWrapper> {
    const mounted = mount(RoleFormDialog, {
        props: { open: true, mode: 'create', permissionCatalog, permissionDependencies, ...props },
    });
    await nextTick();
    return mounted;
}

describe('RoleFormDialog.vue', () => {
    let postSpy: ReturnType<typeof vi.spyOn>;

    beforeEach(() => {
        postSpy = vi.spyOn(router, 'post').mockImplementation(() => {});
    });

    afterEach(() => {
        wrapper?.unmount();
        wrapper = null;
        document.body.innerHTML = '';
        vi.restoreAllMocks();
    });

    it('checks the transitive requirements when a permission is checked', async () => {
        wrapper = await mountDialog();

        await toggle('consultations.create');

        expect(isChecked('patients.view')).toBe(true);
        expect(isChecked('consultations.view')).toBe(true);
        expect(isChecked('consultations.create')).toBe(true);
        expect(isChecked('patients.create')).toBe(false);
    });

    it('unchecks every dependent permission when a requirement is unchecked', async () => {
        wrapper = await mountDialog({
            mode: 'edit',
            role: { id: 1, name: 'Recepción', permissions: [...permissionCatalog] },
        });

        await toggle('patients.view');

        for (const permission of permissionCatalog) {
            expect(isChecked(permission)).toBe(false);
        }
    });

    it('submits the cascaded permissions in catalog order', async () => {
        wrapper = await mountDialog();

        await body().get('input').setValue('Recepción');
        await toggle('consultations.create');
        await submit();

        const [url, payload] = postSpy.mock.calls[0] as [string, Record<string, unknown>];
        expect(url).toBe(store().url);
        expect(payload).toEqual({
            name: 'Recepción',
            permissions: ['patients.view', 'consultations.view', 'consultations.create'],
        });
    });

    it('shows the direct requirements next to each permission that has any', async () => {
        wrapper = await mountDialog();

        expect(fieldText('patients.create')).toContain('Requiere: Pacientes: Ver');
        expect(fieldText('consultations.create')).toContain('Requiere: Consultas: Ver');
        expect(fieldText('patients.view')).not.toContain('Requiere');
    });

    it('shows the server-side permission dependency error after a failed submit', async () => {
        postSpy.mockImplementation((_url: string, _data: unknown, options: VisitOptions) => {
            options.onError?.({ permissions: '«Pacientes: Crear» requiere «Pacientes: Ver».' });
        });
        wrapper = await mountDialog();

        await submit();
        await nextTick();

        expect(body().get('[data-slot="field-error"]').text()).toBe('«Pacientes: Crear» requiere «Pacientes: Ver».');
    });
});
