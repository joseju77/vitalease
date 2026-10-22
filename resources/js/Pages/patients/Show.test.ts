/* eslint-disable vue/one-component-per-file -- minimal inline test doubles for Inertia's Head and Link. */
import { mount, VueWrapper } from '@vue/test-utils';
import { defineComponent, h } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { formatDateTime } from '@/lib/formatDateTime';
import type { ConsultationListItem, PatientProfile } from '@/types/consultations';
import type { PatientAbilities, PatientDetail } from '@/types/patients';

const { pageState, toastSuccess } = vi.hoisted(() => ({
    pageState: { flash: {} as Record<string, unknown>, props: {} as Record<string, unknown> },
    toastSuccess: vi.fn(),
}));

vi.mock('@inertiajs/vue3', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@inertiajs/vue3')>();

    return {
        ...actual,
        Head: defineComponent({
            name: 'InertiaHead',
            props: { title: { type: String, default: '' } },
            setup: () => () => null,
        }),
        Link: defineComponent({
            props: { href: { type: String, required: true } },
            setup:
                (props, { slots }) =>
                () =>
                    h('a', { href: props.href }, slots.default?.()),
        }),
        usePage: () => pageState,
    };
});

vi.mock('vue-sonner', () => ({ toast: { success: toastSuccess } }));

/** The persistent layout is applied by Inertia, not by a direct mount; stubbing it skips its asset imports. */
vi.mock('@/layouts/AppLayout.vue', () => ({ default: {} }));

import Show from './Show.vue';

const profile: PatientProfile = {
    first_name: 'Ana',
    last_name: 'López',
    second_last_name: 'Pérez',
    birth_date: '1990-05-10',
    age: 36,
    sex_at_birth: 2,
    marital_status: 1,
    blood_type: 1,
    enrollment: 'IMSS',
    enrollment_number: 'A-100',
    external_enrollment: null,
    family_medical_unit: { name: 'UMF 1', address: 'Av. Central 10' },
    other_family_medical_unit: null,
    social_security_number: '12345678901',
    contact_information: {
        address: 'Calle 1',
        phone_number: '+521234567890',
        personal_email: 'ana@example.test',
        institutional_email: null,
        neighborhood: 'Centro',
        zip_code: '44100',
        municipality: 'Guadalajara',
    },
    emergency_contacts: [{ name: 'Luis López', phone_number: '+520987654321', kinship_type: 1 }],
    ailments: [],
    other_ailments: null,
    gynecological_history: null,
};

function patientDetail(overrides: Partial<PatientDetail> = {}): PatientDetail {
    return {
        uuid: 'patient-uuid-1',
        full_name: 'Ana López Pérez',
        is_disabled: false,
        disabled: null,
        profile,
        ...overrides,
    };
}

function consultationItem(overrides: Partial<ConsultationListItem> = {}): ConsultationListItem {
    return {
        uuid: 'consultation-uuid-1',
        code: 'CON-0001',
        created_at: '2026-09-01T10:00:00Z',
        voided_at: null,
        diagnosis: 'Migraña',
        medical_classification: 8,
        patient: { uuid: 'patient-uuid-1', full_name: 'Ana López Pérez' },
        physician: { name: 'Dra. Ruiz' },
        can: { update: true, void: true, restore: false, audit: false },
        ...overrides,
    };
}

const noAbilities: PatientAbilities = {
    update: false,
    disable: false,
    enable: false,
    audit: false,
    createConsultation: false,
};

function mountShow(
    patient: PatientDetail = patientDetail(),
    can: Partial<PatientAbilities> = {},
    consultations: ConsultationListItem[] = [],
): VueWrapper {
    return mount(Show, {
        props: { patient, consultations, can: { ...noAbilities, ...can } },
        attachTo: document.body,
    });
}

function buttonLabels(wrapper: VueWrapper): string[] {
    return [...wrapper.findAll('a'), ...wrapper.findAll('button')].map((element) => element.text().trim());
}

const disabledPatient = patientDetail({
    is_disabled: true,
    disabled: { at: '2026-09-05T12:00:00Z', by: 'Dra. Ruiz', reason: 'Baja voluntaria' },
});

describe('Pages/patients/Show.vue', () => {
    beforeEach(() => {
        pageState.flash = {};
        toastSuccess.mockClear();
        document.body.innerHTML = '';
    });

    it('shows the patient name, enrollment number and every profile section', () => {
        const page = mountShow();

        expect(page.get('h1').text()).toBe('Ana López Pérez');
        expect(page.text()).toContain('Inscripción #A-100');
        for (const section of ['general', 'contact', 'emergency-contacts', 'ailments', 'other-ailments']) {
            expect(page.find(`[data-testid="section-${section}"]`).exists()).toBe(true);
        }
        expect(page.find('[data-testid="section-consultations"]').exists()).toBe(true);
    });

    it('shows the sensitive profile fields when the server sends them', () => {
        const page = mountShow();

        expect(page.text()).toContain('12345678901');
        expect(page.text()).toContain('ana@example.test');
        expect(page.text()).toContain('+521234567890');
    });

    it('hides the sensitive profile fields the server withheld for a viewer without patients.view', () => {
        const withheld: PatientProfile = {
            ...profile,
            social_security_number: null,
            contact_information: { ...profile.contact_information!, phone_number: null, personal_email: null },
            emergency_contacts: [{ name: 'Luis López', phone_number: null, kinship_type: 1 }],
        };
        const page = mountShow(patientDetail({ profile: withheld }));

        expect(page.text()).not.toContain('Número de seguridad social');
        expect(page.text()).not.toContain('Correo personal');
        expect(page.text()).not.toContain('Teléfono');
        expect(page.text()).not.toContain('12345678901');
        expect(page.text()).toContain('Calle 1');
    });

    it('shows only the back link when the viewer has no abilities', () => {
        const page = mountShow();

        expect(buttonLabels(page)).not.toContain('Editar');
        expect(buttonLabels(page)).not.toContain('Deshabilitar');
        expect(buttonLabels(page)).not.toContain('Habilitar');
        expect(buttonLabels(page)).not.toContain('Historial de cambios');
        expect(buttonLabels(page)).not.toContain('Nueva consulta');
        expect(page.text()).toContain('Volver a pacientes');
    });

    it('links the edit button to the edit page when can.update is granted', () => {
        const page = mountShow(patientDetail(), { update: true });

        const edit = page.findAll('a').find((link) => link.text().trim() === 'Editar');
        expect(edit?.attributes('href')).toBe('/patients/patient-uuid-1/edit');
    });

    it('shows the disable action only when can.disable is granted', () => {
        expect(mountShow().find('[data-testid="disable-patient"]').exists()).toBe(false);

        const page = mountShow(patientDetail(), { disable: true });
        expect(page.find('[data-testid="disable-patient"]').exists()).toBe(true);
        expect(page.find('[data-testid="enable-patient"]').exists()).toBe(false);
    });

    it('shows the enable action only when can.enable is granted', () => {
        expect(mountShow(disabledPatient).find('[data-testid="enable-patient"]').exists()).toBe(false);

        const page = mountShow(disabledPatient, { enable: true });
        expect(page.find('[data-testid="enable-patient"]').exists()).toBe(true);
        expect(page.find('[data-testid="disable-patient"]').exists()).toBe(false);
    });

    it('shows the change history only when can.audit is granted', () => {
        expect(mountShow().find('[data-testid="patient-history"]').exists()).toBe(false);
        expect(mountShow(patientDetail(), { audit: true }).find('[data-testid="patient-history"]').exists()).toBe(true);
    });

    it('links "Nueva consulta" to the creation page of the patient when can.createConsultation is granted', () => {
        const page = mountShow(patientDetail(), { createConsultation: true });

        const link = page.findAll('a').find((candidate) => candidate.text().trim() === 'Nueva consulta');
        expect(link?.attributes('href')).toBe('/consultations/create?patient=patient-uuid-1');
    });

    it('hides "Nueva consulta" for a disabled patient even when the ability is present', () => {
        const page = mountShow(disabledPatient, { createConsultation: true, enable: true });

        expect(buttonLabels(page)).not.toContain('Nueva consulta');
    });

    it('shows no disabled banner or badge for an active patient', () => {
        const page = mountShow();

        expect(page.find('[data-testid="disabled-patient-banner"]').exists()).toBe(false);
        expect(page.get('h1').text()).not.toContain('Deshabilitado');
    });

    it('shows the disabled banner with date, actor and reason, and a badge in the heading', () => {
        const page = mountShow(disabledPatient);

        const banner = page.get('[data-testid="disabled-patient-banner"]');
        expect(banner.text()).toContain('Paciente deshabilitado');
        expect(banner.text()).toContain(formatDateTime('2026-09-05T12:00:00Z'));
        expect(banner.text()).toContain('por Dra. Ruiz');
        expect(banner.text()).toContain('Motivo: Baja voluntaria');
        expect(page.get('h1').text()).toContain('Deshabilitado');
    });

    it('omits the actor in the banner when the disabling user is unknown', () => {
        const page = mountShow(
            patientDetail({
                is_disabled: true,
                disabled: { at: '2026-09-05T12:00:00Z', by: null, reason: 'Duplicado' },
            }),
        );

        expect(page.get('[data-testid="disabled-patient-banner"]').text()).not.toContain(' por ');
    });

    it('lists the consultations with their detail link, the voided badge and the count', () => {
        const page = mountShow(patientDetail(), {}, [
            consultationItem(),
            consultationItem({ uuid: 'consultation-uuid-2', code: 'CON-0002', voided_at: '2026-09-02T10:00:00Z' }),
        ]);

        const section = page.get('[data-testid="section-consultations"]');
        const links = section.findAll('a');
        expect(links.map((link) => link.attributes('href'))).toEqual([
            '/consultations/consultation-uuid-1',
            '/consultations/consultation-uuid-2',
        ]);
        expect(section.findAll('tbody tr')[0].text()).not.toContain('Anulada');
        expect(section.findAll('tbody tr')[1].text()).toContain('Anulada');
        expect(section.text()).toContain('Dra. Ruiz');
        expect(section.text()).toContain('Neurología');
        expect(section.find('[aria-label="2 consultas"]').exists()).toBe(true);
    });

    it('shows an empty state when the patient has no consultations', () => {
        const page = mountShow();

        expect(page.get('[data-testid="section-consultations"]').text()).toContain(
            'Este paciente no tiene consultas registradas.',
        );
    });

    it('toasts the one-time patientUpdated, patientDisabled and patientEnabled flashes', () => {
        pageState.flash = { patientUpdated: true };
        mountShow();
        pageState.flash = { patientDisabled: true };
        mountShow();
        pageState.flash = { patientEnabled: true };
        mountShow();

        expect(toastSuccess.mock.calls.map(([message]) => message)).toEqual([
            'Paciente actualizado',
            'Paciente deshabilitado',
            'Paciente habilitado',
        ]);
    });
});
