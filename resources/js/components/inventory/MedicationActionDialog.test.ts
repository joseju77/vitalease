import { router } from '@inertiajs/core';
import { flushPromises, mount, VueWrapper } from '@vue/test-utils';
import { nextTick } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { deactivate, destroy } from '@/routes/inventory';
import type { Medication } from '@/types/inventory';

import MedicationActionDialog from './MedicationActionDialog.vue';

/**
 * `router` from `@inertiajs/vue3` is the `@inertiajs/core` router instance, so
 * spying on it intercepts the dialog's real `router.patch()`/`router.delete()`
 * calls, matching `DeleteConsultationDialog.test.ts`'s convention.
 *
 * `AlertDialogContent` teleports to `document.body`, so every DOM query below
 * reaches into `document.body` directly (mounted with `attachTo`), matching
 * `DeleteConsultationDialog.test.ts`.
 */
type VisitOptions = {
    onSuccess?: (page: unknown) => void;
    onError?: (errors: Record<string, string>) => void;
};

const { toastError } = vi.hoisted(() => ({ toastError: vi.fn() }));
vi.mock('vue-sonner', () => ({ toast: { error: toastError } }));

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

function confirmButton(): HTMLButtonElement {
    const alertDialog = document.body.querySelector('[role="alertdialog"]');
    const button = Array.from(alertDialog?.querySelectorAll('button') ?? []).find(
        (candidate) => candidate.textContent?.trim() !== 'Cancelar' && candidate.textContent?.trim() !== '',
    );
    if (!button) {
        throw new Error('No confirm button found in the alert dialog');
    }
    return button;
}

let wrapper: VueWrapper | null = null;

/**
 * `AlertDialogContent` teleports to `document.body` via reka-ui's `AlertDialogPortal`,
 * whose `Teleport` wrapper only swaps its `v-if` placeholder for the real
 * `<Teleport>` once `useMounted()` flips to `true` on the tick after mount —
 * matches `MedicationFormDialog.test.ts`'s `mountDialog()` helper.
 */
async function mountDialog(props: Record<string, unknown>): Promise<VueWrapper> {
    wrapper = mount(MedicationActionDialog, { props, attachTo: document.body });
    await nextTick();
    return wrapper;
}

describe('MedicationActionDialog.vue', () => {
    let patchSpy: ReturnType<typeof vi.spyOn>;
    let deleteSpy: ReturnType<typeof vi.spyOn>;

    beforeEach(() => {
        patchSpy = vi.spyOn(router, 'patch').mockImplementation(() => {});
        deleteSpy = vi.spyOn(router, 'delete').mockImplementation(() => {});
        toastError.mockClear();
    });

    afterEach(() => {
        wrapper?.unmount();
        wrapper = null;
        document.body.innerHTML = '';
        vi.restoreAllMocks();
    });

    it('PATCHes the deactivate URL naming the medication when confirmed', async () => {
        await mountDialog({ open: true, medication: medication(), action: 'deactivate' });

        confirmButton().click();
        await flushPromises();

        expect(patchSpy).toHaveBeenCalledTimes(1);
        expect(deleteSpy).not.toHaveBeenCalled();
        expect(patchSpy.mock.calls[0][0]).toBe(deactivate('med-1').url);
        expect(document.body.querySelector('[role="alertdialog"]')?.textContent).toContain('Desactivar medicamento');
        expect(document.body.querySelector('[role="alertdialog"]')?.textContent).toContain('Paracetamol');
    });

    it('DELETEs the destroy URL naming the medication when confirmed', async () => {
        await mountDialog({
            open: true,
            medication: medication({ uuid: 'med-2', name: 'Ibuprofeno' }),
            action: 'delete',
        });

        expect(document.body.querySelector('[role="alertdialog"]')?.textContent).toContain('Eliminar medicamento');
        expect(document.body.querySelector('[role="alertdialog"]')?.textContent).toContain('Ibuprofeno');

        confirmButton().click();
        await flushPromises();

        expect(deleteSpy).toHaveBeenCalledTimes(1);
        expect(patchSpy).not.toHaveBeenCalled();
        expect(deleteSpy.mock.calls[0][0]).toBe(destroy('med-2').url);
    });

    it('closes the dialog on a successful confirm', async () => {
        patchSpy.mockImplementation((_url: string, _data: unknown, options: VisitOptions) => {
            options.onSuccess?.({});
        });
        const dialog = await mountDialog({ open: true, medication: medication(), action: 'deactivate' });

        confirmButton().click();
        await flushPromises();

        expect(dialog.emitted('update:open')?.at(-1)).toEqual([false]);
    });

    it('shows a toast.error with the server message on a 422 medication error and keeps the dialog open', async () => {
        deleteSpy.mockImplementation((_url: string, options: VisitOptions) => {
            options.onError?.({ medication: 'No se puede eliminar: tiene movimientos registrados.' });
        });
        const dialog = await mountDialog({ open: true, medication: medication(), action: 'delete' });

        confirmButton().click();
        await flushPromises();

        expect(toastError).toHaveBeenCalledWith('No se puede eliminar: tiene movimientos registrados.');
        expect(dialog.emitted('update:open')).toBeUndefined();
    });

    it('sends nothing when cancelled', async () => {
        await mountDialog({ open: true, medication: medication(), action: 'delete' });

        const cancel = Array.from(document.body.querySelectorAll('button')).find(
            (candidate) => candidate.textContent?.trim() === 'Cancelar',
        );
        cancel?.click();
        await flushPromises();

        expect(patchSpy).not.toHaveBeenCalled();
        expect(deleteSpy).not.toHaveBeenCalled();
    });
});
