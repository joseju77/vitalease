import { router } from '@inertiajs/core';
import { DOMWrapper, mount, VueWrapper } from '@vue/test-utils';
import { nextTick } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { store } from '@/routes/inventory/entries';
import type { Medication } from '@/types/inventory';

import StockEntryDialog from './StockEntryDialog.vue';

/**
 * Same conventions as `MedicationFormDialog.test.ts`: the real Inertia
 * `useForm` is used, submits are intercepted at `@inertiajs/core`'s `router`,
 * and `DialogContent` teleports to `document.body` (reka-ui's
 * `DialogPortal`), so every DOM query goes through a `DOMWrapper` over
 * `document.body`.
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
    const mounted = mount(StockEntryDialog, { props });
    await nextTick();
    return mounted;
}

describe('StockEntryDialog.vue', () => {
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

    it('posts to the entries store URL with quantity and notes, defaulting quantity to 1', async () => {
        wrapper = await mountDialog({ open: true, medication: medication() });

        await submit();

        expect(postSpy).toHaveBeenCalledTimes(1);
        const [url, payload] = postSpy.mock.calls[0] as [string, Record<string, unknown>];
        expect(url).toBe(store('med-1').url);
        expect(payload).toEqual({ quantity: 1, notes: '' });
    });

    it('sends the entered quantity and notes', async () => {
        wrapper = await mountDialog({ open: true, medication: medication() });

        await inputByLabel('Cantidad').setValue('25');
        await inputByLabel('Notas').setValue('Recepción de proveedor');

        await submit();

        const [, payload] = postSpy.mock.calls[0] as [string, Record<string, unknown>];
        expect(payload).toEqual({ quantity: 25, notes: 'Recepción de proveedor' });
    });

    it('closes the dialog on a successful submit', async () => {
        postSpy.mockImplementation((_url: string, _data: unknown, options: VisitOptions) => {
            options.onSuccess?.({});
        });
        wrapper = await mountDialog({ open: true, medication: medication() });

        await submit();

        expect(wrapper.emitted('update:open')?.at(-1)).toEqual([false]);
    });

    it('renders the server quantity error inline and creates no movement', async () => {
        postSpy.mockImplementation((_url: string, _data: unknown, options: VisitOptions) => {
            options.onError?.({ quantity: 'La cantidad debe ser al menos 1.' });
        });
        wrapper = await mountDialog({ open: true, medication: medication() });

        await submit();
        await nextTick();

        expect(fieldTextFor(inputByLabel('Cantidad'))).toContain('La cantidad debe ser al menos 1.');
        expect(wrapper.emitted('update:open')).toBeUndefined();
    });

    it('resets the form and clears errors every time the dialog is reopened', async () => {
        postSpy.mockImplementation((_url: string, _data: unknown, options: VisitOptions) => {
            options.onError?.({ quantity: 'La cantidad debe ser al menos 1.' });
        });
        wrapper = await mountDialog({ open: true, medication: medication() });

        await inputByLabel('Cantidad').setValue('50');
        await inputByLabel('Notas').setValue('Nota temporal');
        await submit();
        await nextTick();
        expect(body().text()).toContain('La cantidad debe ser al menos 1.');

        await wrapper.setProps({ open: false });
        await wrapper.setProps({ open: true });

        expect(inputByLabel('Cantidad').element.value).toBe('1');
        expect(inputByLabel('Notas').element.value).toBe('');
        expect(body().text()).not.toContain('La cantidad debe ser al menos 1.');
    });
});
