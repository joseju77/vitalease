import { router } from '@inertiajs/core';
import { flushPromises, mount, VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { restore } from '@/actions/App/Http/Controllers/MedicalConsultationController';
import RestoreConsultationDialog from './RestoreConsultationDialog.vue';

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
    wrapper = mount(RestoreConsultationDialog, {
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

describe('RestoreConsultationDialog.vue', () => {
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

    it('explains that the treatment is dispensed again', async () => {
        await openDialog();

        expect(alertDialog().textContent).toContain('Restaurar CON-0009');
        expect(alertDialog().textContent).toContain('volverán a surtir');
    });

    it('requires a reason', async () => {
        await openDialog();

        await submit();

        expect(alertDialog().textContent).toContain('El motivo es obligatorio.');
        expect(postSpy).not.toHaveBeenCalled();
    });

    it('posts the reason to the restore URL', async () => {
        await openDialog();

        await typeReason('Anulada por error');
        await submit();

        const [url, payload] = postSpy.mock.calls[0] as [string, Record<string, unknown>];
        expect(url).toBe(restore.url('consultation-uuid-9'));
        expect(url).toBe('/consultations/consultation-uuid-9/restore');
        expect(payload).toEqual({ reason: 'Anulada por error' });
    });

    it('shows per-line stock errors and the consultation error, keeping the dialog open', async () => {
        postSpy.mockImplementation((_url: string, _data: unknown, options: VisitOptions) => {
            options.onError?.({
                'treatment.0': 'No se puede restaurar: existencia insuficiente de Paracetamol. Disponible: 1.',
                'treatment.2': 'No se puede restaurar: existencia insuficiente de Ibuprofeno. Disponible: 0.',
                consultation: 'La consulta no está anulada.',
            });
        });
        await openDialog();

        await typeReason('Anulada por error');
        await submit();

        const text = alertDialog().textContent ?? '';
        expect(text).toContain('existencia insuficiente de Paracetamol');
        expect(text).toContain('existencia insuficiente de Ibuprofeno');
        expect(text).toContain('La consulta no está anulada.');
    });

    it('closes the dialog after a successful restore', async () => {
        postSpy.mockImplementation((_url: string, _data: unknown, options: VisitOptions) => {
            options.onSuccess?.({});
        });
        await openDialog();

        await typeReason('Anulada por error');
        await submit();

        expect(document.body.querySelector('[role="alertdialog"]')).toBeNull();
    });
});
