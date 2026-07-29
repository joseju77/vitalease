import { router } from '@inertiajs/core';
import { flushPromises, mount, VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { destroy } from '@/actions/App/Http/Controllers/MedicalConsultationController';
import DeleteConsultationDialog from './DeleteConsultationDialog.vue';

/**
 * `router` from `@inertiajs/vue3` is the `@inertiajs/core` router instance, so
 * spying on it intercepts the dialog's real `router.delete()` call.
 */
let wrapper: VueWrapper | null = null;

async function openDialog(): Promise<VueWrapper> {
    wrapper = mount(DeleteConsultationDialog, {
        props: { uuid: 'consultation-uuid-9', code: 'CON-0009' },
        attachTo: document.body,
    });
    await wrapper.get('button').trigger('click');
    await flushPromises();
    return wrapper;
}

function alertDialogButton(text: string): HTMLButtonElement {
    const alertDialog = document.body.querySelector('[role="alertdialog"]');
    const button = Array.from(alertDialog?.querySelectorAll('button') ?? []).find(
        (candidate) => candidate.textContent?.trim() === text,
    );
    if (!button) {
        throw new Error(`No alert dialog button with text "${text}"`);
    }
    return button;
}

describe('DeleteConsultationDialog.vue', () => {
    let deleteSpy: ReturnType<typeof vi.spyOn>;

    beforeEach(() => {
        deleteSpy = vi.spyOn(router, 'delete').mockImplementation(() => {});
    });

    afterEach(() => {
        wrapper?.unmount();
        wrapper = null;
        document.body.innerHTML = '';
        vi.restoreAllMocks();
    });

    it('asks for confirmation naming the consultation before deleting', async () => {
        await openDialog();

        expect(document.body.querySelector('[role="alertdialog"]')?.textContent).toContain('Eliminar CON-0009');
        expect(deleteSpy).not.toHaveBeenCalled();
    });

    it('sends a DELETE to the consultation destroy URL when confirmed', async () => {
        await openDialog();

        alertDialogButton('Eliminar').click();
        await flushPromises();

        expect(deleteSpy).toHaveBeenCalledTimes(1);
        expect(deleteSpy.mock.calls[0][0]).toBe(destroy.url('consultation-uuid-9'));
        expect(deleteSpy.mock.calls[0][0]).toBe('/consultations/consultation-uuid-9');
    });

    it('sends nothing when cancelled', async () => {
        await openDialog();

        alertDialogButton('Cancelar').click();
        await flushPromises();

        expect(deleteSpy).not.toHaveBeenCalled();
        expect(document.body.querySelector('[role="alertdialog"]')).toBeNull();
    });
});
