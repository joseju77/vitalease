import { router } from '@inertiajs/core';
import { flushPromises, mount, VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { voidConsultation } from '@/actions/App/Http/Controllers/MedicalConsultationController';
import VoidConsultationDialog from './VoidConsultationDialog.vue';

type VisitOptions = {
    onSuccess?: (page: unknown) => void;
    onError?: (errors: Record<string, string>) => void;
};

let wrapper: VueWrapper | null = null;

function alertDialog(): HTMLElement {
    const element = document.body.querySelector<HTMLElement>('[role="alertdialog"]');
    if (!element) {
        throw new Error('The alert dialog is not open');
    }
    return element;
}

async function openDialog(): Promise<void> {
    wrapper = mount(VoidConsultationDialog, {
        props: { uuid: 'consultation-uuid-9', code: 'CON-0009' },
        attachTo: document.body,
    });
    await wrapper.get('button').trigger('click');
    await flushPromises();
}

async function typeReason(value: string): Promise<void> {
    const textarea = alertDialog().querySelector('textarea');
    if (!textarea) {
        throw new Error('No reason textarea');
    }
    textarea.value = value;
    textarea.dispatchEvent(new Event('input', { bubbles: true }));
    await flushPromises();
}

async function submit(): Promise<void> {
    alertDialog()
        .querySelector('form')
        ?.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    await flushPromises();
}

describe('VoidConsultationDialog.vue', () => {
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

    it('explains that stock is restored and that the consultation can be restored later', async () => {
        await openDialog();

        expect(alertDialog().textContent).toContain('Anular CON-0009');
        expect(alertDialog().textContent).toContain('devolverá al inventario');
        expect(alertDialog().textContent).toContain('puede restaurarse más adelante');
        expect(postSpy).not.toHaveBeenCalled();
    });

    it('requires a reason and sends nothing without one', async () => {
        await openDialog();

        await submit();
        expect(alertDialog().textContent).toContain('El motivo es obligatorio.');

        await typeReason('   ');
        await submit();

        expect(postSpy).not.toHaveBeenCalled();
    });

    it('rejects a reason over 500 characters', async () => {
        await openDialog();

        await typeReason('a'.repeat(501));
        await submit();

        expect(alertDialog().textContent).toContain('500 caracteres');
        expect(postSpy).not.toHaveBeenCalled();
    });

    it('posts the trimmed reason to the void URL', async () => {
        await openDialog();

        await typeReason('  Registro duplicado  ');
        await submit();

        expect(postSpy).toHaveBeenCalledTimes(1);
        const [url, payload] = postSpy.mock.calls[0] as [string, Record<string, unknown>];
        expect(url).toBe(voidConsultation.url('consultation-uuid-9'));
        expect(url).toBe('/consultations/consultation-uuid-9/void');
        expect(payload).toEqual({ reason: 'Registro duplicado' });
    });

    it('closes the dialog after a successful submit', async () => {
        postSpy.mockImplementation((_url: string, _data: unknown, options: VisitOptions) => {
            options.onSuccess?.({});
        });
        await openDialog();

        await typeReason('Registro duplicado');
        await submit();

        expect(document.body.querySelector('[role="alertdialog"]')).toBeNull();
    });

    it('shows server validation errors and keeps the dialog open', async () => {
        postSpy.mockImplementation((_url: string, _data: unknown, options: VisitOptions) => {
            options.onError?.({ reason: 'El motivo no es válido.', consultation: 'La consulta ya fue anulada.' });
        });
        await openDialog();

        await typeReason('Registro duplicado');
        await submit();

        expect(alertDialog().textContent).toContain('El motivo no es válido.');
        expect(alertDialog().textContent).toContain('La consulta ya fue anulada.');
    });

    it('sends nothing when cancelled', async () => {
        await openDialog();

        const cancel = Array.from(alertDialog().querySelectorAll('button')).find(
            (button) => button.textContent?.trim() === 'Cancelar',
        );
        cancel?.click();
        await flushPromises();

        expect(postSpy).not.toHaveBeenCalled();
        expect(document.body.querySelector('[role="alertdialog"]')).toBeNull();
    });
});
