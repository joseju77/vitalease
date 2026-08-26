import { router } from '@inertiajs/core';
import { DOMWrapper, mount, VueWrapper } from '@vue/test-utils';
import { nextTick } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { store, update } from '@/routes/inventory';
import type { Medication } from '@/types/inventory';

import MedicationFormDialog from './MedicationFormDialog.vue';

/**
 * `MedicationFormDialog.vue` uses Inertia's REAL `useForm`; submits are
 * intercepted at `@inertiajs/core`'s `router`, the exact object the real form
 * dispatches to, matching `ConsultationForm.test.ts`'s convention.
 *
 * `DialogContent` teleports to `document.body` (reka-ui's `DialogPortal`), so
 * it never appears under the mounted wrapper's own root element. Every DOM
 * query below goes through a fresh `DOMWrapper` over `document.body` instead
 * of the `VueWrapper`, matching how `PatientSummaryDialog.test.ts` reaches
 * into its own teleported dialog content.
 */
type VisitOptions = {
    onSuccess?: (page: unknown) => void;
    onError?: (errors: Record<string, string>) => void;
};

function medication(overrides: Partial<Medication> = {}): Medication {
    return {
        uuid: 'med-1',
        name: 'Paracetamol',
        presentation: 'Tableta',
        concentration: '500 mg',
        dispensing_unit: 'tableta',
        current_stock: 100,
        minimum_stock: 20,
        is_active: true,
        is_low_stock: false,
        can_be_deleted: true,
        ...overrides,
    };
}

function body(): DOMWrapper<HTMLElement> {
    return new DOMWrapper(document.body);
}

/** Finds a field's control by its exact `FieldLabel` text, following the `for`/`id` pairing. */
function inputByLabel(labelText: string): DOMWrapper<HTMLInputElement> {
    const label = body()
        .findAll('label')
        .find((candidate) => candidate.text().trim() === labelText);
    if (!label) {
        throw new Error(`No label found with text "${labelText}"`);
    }
    const controlId = label.attributes('for');
    return body().get<HTMLInputElement>(`#${controlId}`);
}

function fieldTextFor(control: DOMWrapper<HTMLInputElement>): string {
    const field = control.element.closest('[data-slot="field"]');
    if (!field) {
        throw new Error('No field wrapper found for control');
    }
    return field.textContent ?? '';
}

async function submit() {
    await body().get('form').trigger('submit');
}

let wrapper: VueWrapper | null = null;

/**
 * `reka-ui`'s `Teleport` wrapper only swaps its `v-if` placeholder for the
 * real `<Teleport>` once `useMounted()` flips to `true` on the next tick
 * after mount, so every mount needs one `nextTick()` before the dialog
 * content actually lands in `document.body`.
 */
async function mountDialog(props: Record<string, unknown>): Promise<VueWrapper> {
    const mounted = mount(MedicationFormDialog, { props });
    await nextTick();
    return mounted;
}

describe('MedicationFormDialog.vue', () => {
    let postSpy: ReturnType<typeof vi.spyOn>;
    let patchSpy: ReturnType<typeof vi.spyOn>;

    beforeEach(() => {
        postSpy = vi.spyOn(router, 'post').mockImplementation(() => {});
        patchSpy = vi.spyOn(router, 'patch').mockImplementation(() => {});
    });

    afterEach(() => {
        wrapper?.unmount();
        wrapper = null;
        document.body.innerHTML = '';
        vi.restoreAllMocks();
    });

    it('creates via POST to the store URL with the form payload, defaulting is_active to true', async () => {
        wrapper = await mountDialog({ open: true, mode: 'create' });

        await inputByLabel('Nombre').setValue('Ibuprofeno');
        await inputByLabel('Presentación').setValue('Tableta');
        await inputByLabel('Concentración').setValue('400 mg');
        await inputByLabel('Unidad de dispensación').setValue('tableta');
        await inputByLabel('Existencia mínima').setValue('10');

        await submit();

        expect(postSpy).toHaveBeenCalledTimes(1);
        expect(patchSpy).not.toHaveBeenCalled();
        const [url, payload] = postSpy.mock.calls[0] as [string, Record<string, unknown>];
        expect(url).toBe(store().url);
        expect(payload).toEqual({
            name: 'Ibuprofeno',
            presentation: 'Tableta',
            concentration: '400 mg',
            dispensing_unit: 'tableta',
            minimum_stock: 10,
            is_active: true,
        });
    });

    it('prefills the form in edit mode and updates via PATCH to the update URL', async () => {
        const existing = medication({ uuid: 'med-9', name: 'Amoxicilina', minimum_stock: 15, is_active: false });
        wrapper = await mountDialog({ open: true, mode: 'edit', medication: existing });

        expect(inputByLabel('Nombre').element.value).toBe('Amoxicilina');
        expect(inputByLabel('Existencia mínima').element.value).toBe('15');

        await submit();

        expect(patchSpy).toHaveBeenCalledTimes(1);
        expect(postSpy).not.toHaveBeenCalled();
        const [url, payload] = patchSpy.mock.calls[0] as [string, Record<string, unknown>];
        expect(url).toBe(update(existing.uuid).url);
        expect(payload).toEqual({
            name: 'Amoxicilina',
            presentation: 'Tableta',
            concentration: '500 mg',
            dispensing_unit: 'tableta',
            minimum_stock: 15,
            is_active: false,
        });
    });

    it('closes the dialog on a successful submit', async () => {
        postSpy.mockImplementation((_url: string, _data: unknown, options: VisitOptions) => {
            options.onSuccess?.({});
        });
        wrapper = await mountDialog({ open: true, mode: 'create' });

        await submit();

        expect(wrapper.emitted('update:open')?.at(-1)).toEqual([false]);
    });

    it('resets the form and clears errors every time the dialog is reopened', async () => {
        postSpy.mockImplementation((_url: string, _data: unknown, options: VisitOptions) => {
            options.onError?.({ name: 'El nombre ya está en uso.' });
        });
        wrapper = await mountDialog({ open: true, mode: 'create' });

        await inputByLabel('Nombre').setValue('Nombre temporal');
        await submit();
        await nextTick();
        expect(body().text()).toContain('El nombre ya está en uso.');

        await wrapper.setProps({ open: false });
        await wrapper.setProps({ open: true });

        expect(inputByLabel('Nombre').element.value).toBe('');
        expect(body().text()).not.toContain('El nombre ya está en uso.');
    });

    it('renders inline server errors under each corresponding field after a failed submit', async () => {
        postSpy.mockImplementation((_url: string, _data: unknown, options: VisitOptions) => {
            options.onError?.({
                name: 'El nombre es obligatorio.',
                presentation: 'La presentación es obligatoria.',
                concentration: 'La concentración es obligatoria.',
                dispensing_unit: 'La unidad de dispensación es obligatoria.',
                minimum_stock: 'La existencia mínima es obligatoria.',
            });
        });
        wrapper = await mountDialog({ open: true, mode: 'create' });

        await submit();
        await nextTick();

        expect(fieldTextFor(inputByLabel('Nombre'))).toContain('El nombre es obligatorio.');
        expect(fieldTextFor(inputByLabel('Presentación'))).toContain('La presentación es obligatoria.');
        expect(fieldTextFor(inputByLabel('Concentración'))).toContain('La concentración es obligatoria.');
        expect(fieldTextFor(inputByLabel('Unidad de dispensación'))).toContain(
            'La unidad de dispensación es obligatoria.',
        );
        expect(fieldTextFor(inputByLabel('Existencia mínima'))).toContain('La existencia mínima es obligatoria.');
    });
});
