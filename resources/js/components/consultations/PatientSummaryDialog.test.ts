import { flushPromises, mount, VueWrapper } from '@vue/test-utils';
import { defineComponent, h } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { patientSummary } from '@/actions/App/Http/Controllers/DashboardController';
import { create, show } from '@/actions/App/Http/Controllers/MedicalConsultationController';
import type { ConsultationListItem, PatientSearchResult, PatientSummary } from '@/types/consultations';

vi.mock('@inertiajs/vue3', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@inertiajs/vue3')>();

    return {
        ...actual,
        Link: defineComponent({
            props: { href: { type: String, required: true } },
            setup:
                (props, { slots }) =>
                () =>
                    h('a', { href: props.href }, slots.default?.()),
        }),
    };
});

import PatientSummaryDialog from './PatientSummaryDialog.vue';

const patient: PatientSearchResult = {
    uuid: 'patient-uuid-1',
    first_name: 'Ana',
    last_name: 'López',
    second_last_name: 'Pérez',
    enrollment_number: 'A-100',
};

function consultationItem(overrides: Partial<ConsultationListItem> = {}): ConsultationListItem {
    return {
        uuid: 'consultation-uuid-1',
        code: 'CON-0001',
        created_at: '2026-09-01T10:00:00Z',
        diagnosis: 'Migraña',
        medical_classification: 8,
        patient: { uuid: patient.uuid, full_name: 'Ana López Pérez' },
        physician: { name: 'Dra. Ruiz' },
        can: { update: true, delete: true },
        ...overrides,
    };
}

function summary(latestConsultations: ConsultationListItem[] = []): PatientSummary {
    return {
        patient: {
            uuid: patient.uuid,
            first_name: 'Ana',
            last_name: 'López',
            second_last_name: 'Pérez',
            birth_date: '1990-05-10',
            sex_at_birth: 2,
            blood_type: 8,
            enrollment_number: 'A-100',
        },
        latest_consultations: latestConsultations,
    };
}

let fetchMock: ReturnType<typeof vi.fn>;
let wrapper: VueWrapper | null = null;

function respondWith(body: unknown, ok = true) {
    fetchMock.mockResolvedValue({ ok, json: async () => body });
}

async function mountDialog(props: { open: boolean; canCreateConsultation?: boolean }) {
    wrapper = mount(PatientSummaryDialog, {
        props: { patient, canCreateConsultation: props.canCreateConsultation ?? true, open: props.open },
        attachTo: document.body,
    });
    await flushPromises();
    return wrapper;
}

function dialogElement(): HTMLElement {
    const dialog = document.body.querySelector<HTMLElement>('[role="dialog"]');
    if (!dialog) {
        throw new Error('The dialog is not open');
    }
    return dialog;
}

function dialogLinks(): HTMLAnchorElement[] {
    return Array.from(dialogElement().querySelectorAll('a'));
}

describe('PatientSummaryDialog.vue', () => {
    beforeEach(() => {
        fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
    });

    afterEach(() => {
        wrapper?.unmount();
        wrapper = null;
        document.body.innerHTML = '';
        vi.unstubAllGlobals();
    });

    it('does not fetch the summary while closed', async () => {
        respondWith(summary());

        await mountDialog({ open: false });

        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('fetches the patient summary as JSON when opened', async () => {
        respondWith(summary());

        await mountDialog({ open: true });

        expect(fetchMock).toHaveBeenCalledTimes(1);
        const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
        expect(url).toBe(patientSummary.url(patient.uuid));
        expect(init.headers).toEqual({ Accept: 'application/json' });
    });

    it('fetches the summary when a closed dialog is opened', async () => {
        respondWith(summary());
        const dialog = await mountDialog({ open: false });

        await dialog.setProps({ open: true });
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('shows the basic patient data with labeled enum values', async () => {
        respondWith(summary());

        await mountDialog({ open: true });

        const text = dialogElement().textContent ?? '';
        expect(text).toContain('Ana López Pérez');
        expect(text).toContain('A-100');
        expect(text).toContain('1990-05-10');
        expect(text).toContain('Femenino');
        expect(text).toContain('O-');
    });

    it('labels a patient without enrollment number', async () => {
        const withoutEnrollment = summary();
        withoutEnrollment.patient.enrollment_number = null;
        respondWith(withoutEnrollment);

        await mountDialog({ open: true });

        expect(dialogElement().textContent).toContain('Sin inscripción');
    });

    it('lists the consultations sent by the server linking to their detail page', async () => {
        respondWith(
            summary([
                consultationItem(),
                consultationItem({ uuid: 'consultation-uuid-2', code: 'CON-0002', medical_classification: 6 }),
            ]),
        );

        await mountDialog({ open: true });

        const text = dialogElement().textContent ?? '';
        expect(text).toContain('CON-0001');
        expect(text).toContain('Neurología');
        expect(text).toContain('CON-0002');
        expect(text).toContain('Cardiología');
        const hrefs = dialogLinks().map((link) => link.getAttribute('href'));
        expect(hrefs).toContain(show.url('consultation-uuid-1'));
        expect(hrefs).toContain(show.url('consultation-uuid-2'));
    });

    it('shows an empty state when the patient has no consultations', async () => {
        respondWith(summary([]));

        await mountDialog({ open: true });

        expect(dialogElement().textContent).toContain('Este paciente no tiene consultas registradas.');
    });

    it('offers a new consultation link for the patient when creation is allowed', async () => {
        respondWith(summary());

        await mountDialog({ open: true, canCreateConsultation: true });

        const newConsultation = dialogLinks().find((link) => link.textContent?.trim() === 'Nueva consulta');
        expect(newConsultation?.getAttribute('href')).toBe(create.url({ query: { patient: patient.uuid } }));
        expect(newConsultation?.getAttribute('href')).toBe('/consultations/create?patient=patient-uuid-1');
    });

    it('hides the new consultation link when creation is not allowed', async () => {
        respondWith(summary());

        await mountDialog({ open: true, canCreateConsultation: false });

        expect(dialogElement().textContent).toContain('Ana López Pérez');
        expect(dialogLinks().some((link) => link.textContent?.trim() === 'Nueva consulta')).toBe(false);
    });

    it('shows an error message when the summary request fails', async () => {
        respondWith({}, false);

        await mountDialog({ open: true });

        expect(dialogElement().textContent).toContain('No se pudo cargar la información del paciente.');
    });
});
