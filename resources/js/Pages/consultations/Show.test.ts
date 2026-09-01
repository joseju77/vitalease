/* eslint-disable vue/one-component-per-file -- minimal inline test doubles for Inertia's Head and Link. */
import { mount, VueWrapper } from '@vue/test-utils';
import { defineComponent, h } from 'vue';
import { describe, expect, it, vi } from 'vitest';
import { index as dashboard } from '@/actions/App/Http/Controllers/DashboardController';
import { edit } from '@/actions/App/Http/Controllers/MedicalConsultationController';
import type { ConsultationAggregate, PatientProfile } from '@/types/consultations';

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
    };
});

/** The persistent layout is applied by Inertia, not by a direct mount; stubbing it skips its asset imports. */
vi.mock('@/layouts/AppLayout.vue', () => ({ default: {} }));

import Show from './Show.vue';

const patientProfile: PatientProfile = {
    first_name: 'Ana',
    last_name: 'López',
    second_last_name: null,
    birth_date: '1990-05-10',
    age: 36,
    sex_at_birth: 2,
    marital_status: 1,
    blood_type: 1,
    enrollment: null,
    enrollment_number: 'A-100',
    external_enrollment: null,
    family_medical_unit: null,
    other_family_medical_unit: null,
    social_security_number: '12345678901',
    contact_information: null,
    emergency_contacts: [],
    ailments: [],
    other_ailments: null,
    gynecological_history: null,
};

function consultation(overrides: Partial<ConsultationAggregate> = {}): ConsultationAggregate {
    return {
        uuid: 'consultation-uuid-9',
        code: 'CON-0009',
        created_at: '2026-09-01T10:00:00Z',
        patient: { uuid: 'patient-uuid-1', full_name: 'Ana López', enrollment_number: 'A-100' },
        physician: { name: 'Dra. Ruiz' },
        consultation: { current_condition: 'Cefalea intensa', diagnosis: 'Migraña sin aura' },
        condition: 2,
        prognosis: 5,
        medical_classification: 8,
        treatment: [
            {
                medication: {
                    uuid: 'med-sumatriptan',
                    name: 'Sumatriptán',
                    presentation: 'Tableta',
                    concentration: '50 mg',
                    dispensing_unit: 'tableta',
                    is_active: true,
                },
                quantity_dispensed: 2,
                dose: '50 mg',
                frequency: 'c/12h',
                duration: '2 días',
            },
        ],
        vital_signs: {
            weight: '70',
            height: '1.70',
            blood_pressure_systolic: '120',
            blood_pressure_diastolic: '80',
            heart_rate: '72',
            respiratory_rate: '16',
            temperature: '36.5',
            oxygen_saturation: '98',
            glasgow: '15',
            glucose: '95',
        },
        physical_examination: {
            neurological: 'Fotofobia',
            head_neck: 'Sin alteraciones',
            thorax_cardiopulmonary: 'Sin alteraciones',
            abdomen: 'Blando',
            extremities: 'Íntegras',
            cabinet_laboratory: 'Pendiente',
        },
        regulation: {
            transfer_type: 2,
            regulated_at: '2026-09-01T11:00',
            ambulance_registration: 'AMB-7',
            regulation_number: '',
            clinic_id: '',
            receiver_physician: 'Dr. Soto',
        },
        can: { update: true, delete: true },
        ...overrides,
    };
}

function mountShow(overrides: Partial<ConsultationAggregate> = {}): VueWrapper {
    return mount(Show, { props: { consultation: consultation(overrides), patientProfile } });
}

/** Returns the `<dd>` text paired with the `<dt>` whose text equals `term`. */
function definitionFor(wrapper: VueWrapper, term: string): string {
    const termElement = wrapper.findAll('dt').find((dt) => dt.text() === term);
    if (!termElement) {
        throw new Error(`No definition term "${term}"`);
    }
    return termElement.element.nextElementSibling?.textContent?.trim() ?? '';
}

describe('Pages/consultations/Show.vue', () => {
    it('titles the page with the consultation code, physician, and patient', () => {
        const wrapper = mountShow();

        expect(wrapper.get('h1').text()).toBe('Consulta CON-0009');
        expect(wrapper.findComponent({ name: 'InertiaHead' }).props('title')).toBe('CON-0009');
        expect(wrapper.text()).toContain('Dra. Ruiz');
        expect(wrapper.text()).toContain('Ana López');
    });

    it('renders the consultation read-only with labeled values', () => {
        const wrapper = mountShow();

        expect(wrapper.findAll('input, textarea, select')).toHaveLength(0);
        expect(definitionFor(wrapper, 'Padecimiento actual')).toBe('Cefalea intensa');
        expect(definitionFor(wrapper, 'Diagnóstico')).toBe('Migraña sin aura');
        expect(definitionFor(wrapper, 'Estado')).toBe('Regular');
        expect(definitionFor(wrapper, 'Pronóstico')).toBe('Reservado');
        expect(definitionFor(wrapper, 'Clasificación médica')).toBe('Neurología');
        expect(definitionFor(wrapper, 'TA')).toContain('120/80');
        expect(definitionFor(wrapper, 'Glucosa')).toContain('95');
        expect(definitionFor(wrapper, 'Neurológico')).toBe('Fotofobia');
        expect(wrapper.text()).toContain('Sumatriptán');
        expect(wrapper.text()).toContain('Tableta 50 mg');
        expect(wrapper.text()).toContain('Cantidad: 2 tableta');
        expect(definitionFor(wrapper, 'Tipo de traslado')).toBe('Servicios de Salud Municipales');
        expect(definitionFor(wrapper, 'Registro de ambulancia')).toBe('AMB-7');
        expect(definitionFor(wrapper, 'Médico receptor')).toBe('Dr. Soto');
    });

    it('marks missing optional values as not recorded', () => {
        const base = consultation();
        const wrapper = mountShow({ vital_signs: { ...base.vital_signs, glucose: '' } });

        expect(definitionFor(wrapper, 'Glucosa')).toBe('No registrado');
        expect(definitionFor(wrapper, 'Número de regulación')).toBe('No registrado');
        expect(definitionFor(wrapper, 'Clínica')).toBe('No registrado');
    });

    it('shows empty-state text without treatment or regulation', () => {
        const wrapper = mountShow({ treatment: [], regulation: null });

        expect(wrapper.text()).toContain('Sin medicamentos');
        expect(wrapper.text()).toContain('Sin regulación médica.');
        expect(wrapper.findAll('dt').some((dt) => dt.text() === 'Tipo de traslado')).toBe(false);
    });

    it('links back to the dashboard', () => {
        const wrapper = mountShow();

        const backLink = wrapper.findAll('a').find((link) => link.text() === 'Volver al panel');
        expect(backLink?.attributes('href')).toBe(dashboard.url());
    });

    it('shows the edit link only when the user can update the consultation', () => {
        const editable = mountShow({ can: { update: true, delete: true } });
        const editLink = editable.findAll('a').find((link) => link.text() === 'Editar');
        expect(editLink?.attributes('href')).toBe(edit.url('consultation-uuid-9'));

        const readOnly = mountShow({ can: { update: false, delete: true } });
        expect(readOnly.findAll('a').some((link) => link.text() === 'Editar')).toBe(false);
    });

    it('never offers a delete control on the detail page', () => {
        const wrapper = mountShow({ can: { update: true, delete: true } });

        expect(wrapper.text()).not.toContain('Eliminar');
    });
});
