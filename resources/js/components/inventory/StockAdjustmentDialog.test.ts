import { router } from '@inertiajs/core';
import { DOMWrapper, mount, VueWrapper } from '@vue/test-utils';
import { nextTick } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { store } from '@/routes/inventory/adjustments';
import type { Medication } from '@/types/inventory';

import StockAdjustmentDialog from './StockAdjustmentDialog.vue';

/**
 * Same conventions as `MedicationFormDialog.test.ts`: the real Inertia
 * `useForm` is used, submits are intercepted at `@inertiajs/core`'s `router`,
 * and `DialogContent` teleports to `document.body` (reka-ui's
 * `DialogPortal`), so every DOM query goes through a `DOMWrapper` over
 * `document.body`. The direction field uses `SelectField`'s `native-select`
 * mode, which renders a plain `<select>`, so it can be driven the same way
 * as any other labeled control.
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

/**
 * `TextareaField` (unlike `TextField`/`SelectField`) has no
 * `hide-required-asterisk` prop, so its "Notas" label renders as "Notas*"
 * since it is required here; matching by prefix keeps this helper working
 * for both plain and required-marked labels.
 */
function controlByLabel(labelText: string): DOMWrapper<HTMLInputElement | HTMLSelectElement> {
    const label = body()
        .findAll('label')
        .find((candidate) => candidate.text().trim().replace(/\*$/, '') === labelText);
    if (!label) {
        throw new Error(`No label found with text "${labelText}"`);
    }
    const controlId = label.attributes('for');
    return body().get<HTMLInputElement | HTMLSelectElement>(`#${controlId}`);
}

function fieldTextFor(control: DOMWrapper<HTMLInputElement | HTMLSelectElement>): string {
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
    const mounted = mount(StockAdjustmentDialog, { props });
    await nextTick();
    return mounted;
}

describe('StockAdjustmentDialog.vue', () => {
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

    it('posts to the adjustments store URL with a positive signed quantity when increasing', async () => {
        wrapper = await mountDialog({ open: true, medication: medication() });

        await controlByLabel('Cantidad').setValue('15');
        await controlByLabel('Notas').setValue('Conteo físico');

        await submit();

        expect(postSpy).toHaveBeenCalledTimes(1);
        const [url, payload] = postSpy.mock.calls[0] as [string, Record<string, unknown>];
        expect(url).toBe(store('med-1').url);
        expect(payload).toEqual({ quantity: 15, notes: 'Conteo físico' });
    });

    it('transforms the positive magnitude into a negative quantity when the direction is "Disminuir"', async () => {
        wrapper = await mountDialog({ open: true, medication: medication() });

        await controlByLabel('Tipo de ajuste').setValue('decrease');
        await controlByLabel('Cantidad').setValue('7');
        await controlByLabel('Notas').setValue('Merma por caducidad');

        await submit();

        const [, payload] = postSpy.mock.calls[0] as [string, Record<string, unknown>];
        expect(payload).toEqual({ quantity: -7, notes: 'Merma por caducidad' });
    });

    it('closes the dialog on a successful submit', async () => {
        postSpy.mockImplementation((_url: string, _data: unknown, options: VisitOptions) => {
            options.onSuccess?.({});
        });
        wrapper = await mountDialog({ open: true, medication: medication() });

        await controlByLabel('Notas').setValue('Conteo físico');
        await submit();

        expect(wrapper.emitted('update:open')?.at(-1)).toEqual([false]);
    });

    it('renders the server quantity error under the magnitude field', async () => {
        postSpy.mockImplementation((_url: string, _data: unknown, options: VisitOptions) => {
            options.onError?.({ quantity: 'La existencia no puede quedar negativa.' });
        });
        wrapper = await mountDialog({ open: true, medication: medication() });

        await controlByLabel('Notas').setValue('Conteo físico');
        await submit();
        await nextTick();

        expect(fieldTextFor(controlByLabel('Cantidad'))).toContain('La existencia no puede quedar negativa.');
    });

    it('renders the server notes-required error inline', async () => {
        postSpy.mockImplementation((_url: string, _data: unknown, options: VisitOptions) => {
            options.onError?.({ notes: 'Las notas son obligatorias.' });
        });
        wrapper = await mountDialog({ open: true, medication: medication() });

        await submit();
        await nextTick();

        expect(fieldTextFor(controlByLabel('Notas'))).toContain('Las notas son obligatorias.');
    });

    it('resets the form and clears errors every time the dialog is reopened', async () => {
        postSpy.mockImplementation((_url: string, _data: unknown, options: VisitOptions) => {
            options.onError?.({ notes: 'Las notas son obligatorias.' });
        });
        wrapper = await mountDialog({ open: true, medication: medication() });

        await controlByLabel('Tipo de ajuste').setValue('decrease');
        await controlByLabel('Cantidad').setValue('9');
        await submit();
        await nextTick();
        expect(body().text()).toContain('Las notas son obligatorias.');

        await wrapper.setProps({ open: false });
        await wrapper.setProps({ open: true });

        expect((controlByLabel('Tipo de ajuste').element as HTMLSelectElement).value).toBe('increase');
        expect(controlByLabel('Cantidad').element.value).toBe('1');
        expect(controlByLabel('Notas').element.value).toBe('');
        expect(body().text()).not.toContain('Las notas son obligatorias.');
    });
});
