import { router } from '@inertiajs/core';
import { flushPromises, mount, VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import DisablePatientDialog from './DisablePatientDialog.vue';

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
    wrapper = mount(DisablePatientDialog, {
        props: { uuid: 'patient-uuid-9', name: 'Ana López' },
        attachTo: document.body,
    });
    await wrapper.get('[data-testid="disable-patient"]').trigger('click');
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

describe('DisablePatientDialog.vue', () => {
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

    it('renders a trigger labelled "Deshabilitar" and opens nothing until it is clicked', () => {
        wrapper = mount(DisablePatientDialog, {
            props: { uuid: 'patient-uuid-9', name: 'Ana López' },
            attachTo: document.body,
        });

        expect(wrapper.get('[data-testid="disable-patient"]').text()).toBe('Deshabilitar');
        expect(document.body.querySelector('[role="alertdialog"]')).toBeNull();
    });

    it('names the patient in the title and shows the required reason field', async () => {
        await openDialog();

        expect(alertDialog().textContent).toContain('Deshabilitar a Ana López');
        expect(alertDialog().querySelector('textarea')).not.toBeNull();
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

    it('accepts a reason of exactly 500 characters', async () => {
        await openDialog();

        await typeReason('a'.repeat(500));
        await submit();

        expect(postSpy).toHaveBeenCalledTimes(1);
    });

    it('posts the trimmed reason to the disable URL of the patient', async () => {
        await openDialog();

        await typeReason('  Motivo clínico  ');
        await submit();

        expect(postSpy).toHaveBeenCalledTimes(1);
        const [url, payload] = postSpy.mock.calls[0] as [string, Record<string, unknown>];
        expect(url).toBe('/patients/patient-uuid-9/disable');
        expect(payload).toEqual({ reason: 'Motivo clínico' });
    });

    it('closes the dialog after a successful submit', async () => {
        postSpy.mockImplementation((_url: string, _data: unknown, options: VisitOptions) => {
            options.onSuccess?.({});
        });
        await openDialog();

        await typeReason('Motivo clínico');
        await submit();

        expect(document.body.querySelector('[role="alertdialog"]')).toBeNull();
    });

    it('shows server validation and state errors and keeps the dialog open', async () => {
        postSpy.mockImplementation((_url: string, _data: unknown, options: VisitOptions) => {
            options.onError?.({ reason: 'El motivo no es válido.', patient: 'El paciente ya cambió de estado.' });
        });
        await openDialog();

        await typeReason('Motivo clínico');
        await submit();

        expect(alertDialog().textContent).toContain('El motivo no es válido.');
        expect(alertDialog().textContent).toContain('El paciente ya cambió de estado.');
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
