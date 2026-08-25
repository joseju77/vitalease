/* eslint-disable vue/one-component-per-file -- minimal inline test doubles for Inertia's Head and Link. */
import { mount } from '@vue/test-utils';
import { defineComponent, h } from 'vue';
import { describe, expect, it, vi } from 'vitest';
import { show } from '@/actions/App/Http/Controllers/MedicalConsultationController';
import ComboboxField from '@/components/form/ComboboxField.vue';
import type { ConsultationAggregate, MedicationOption, PatientProfile } from '@/types/consultations';

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

import Edit from './Edit.vue';

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

const medicationOptions: MedicationOption[] = [
    {
        uuid: 'med-paracetamol',
        name: 'Paracetamol',
        presentation: 'Tableta',
        concentration: '500 mg',
        dispensing_unit: 'tableta',
        current_stock: 120,
        is_active: true,
    },
];

function consultation(
    can: ConsultationAggregate['can'],
    treatment: ConsultationAggregate['treatment'] = [],
): ConsultationAggregate {
    return {
        uuid: 'consultation-uuid-9',
        code: 'CON-0009',
        created_at: '2026-09-01T10:00:00Z',
        patient: { uuid: 'patient-uuid-1', full_name: 'Ana López', enrollment_number: 'A-100' },
        physician: { name: 'Dra. Ruiz' },
        consultation: { current_condition: 'Cefalea', diagnosis: 'Migraña' },
        condition: 1,
        prognosis: 1,
        medical_classification: 8,
        treatment,
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
            glucose: '',
        },
        physical_examination: {
            neurological: 'Normal',
            head_neck: 'Normal',
            thorax_cardiopulmonary: 'Normal',
            abdomen: 'Normal',
            extremities: 'Normal',
            cabinet_laboratory: 'Normal',
        },
        regulation: null,
        can,
    };
}

function mountEdit(can: ConsultationAggregate['can'], treatment: ConsultationAggregate['treatment'] = []) {
    return mount(Edit, {
        props: {
            consultation: consultation(can, treatment),
            patientProfile,
            medicalStateOptions: [1, 2],
            medicalClassificationOptions: [8],
            transferTypeOptions: [1],
            medicationOptions,
        },
    });
}

function hasDeleteControl(wrapper: ReturnType<typeof mountEdit>): boolean {
    return wrapper.findAll('button').some((button) => button.text().includes('Eliminar consulta'));
}

describe('Pages/consultations/Edit.vue', () => {
    it('titles the page with the consultation code and links back to its detail', () => {
        const wrapper = mountEdit({ update: true, delete: true });

        expect(wrapper.get('h1').text()).toBe('Editar CON-0009');
        expect(wrapper.findComponent({ name: 'InertiaHead' }).props('title')).toBe('Editar CON-0009');
        const detailLink = wrapper.findAll('a').find((link) => link.text() === 'Ver detalle');
        expect(detailLink?.attributes('href')).toBe(show.url('consultation-uuid-9'));
    });

    it('shows the delete control when the user can delete the consultation', () => {
        expect(hasDeleteControl(mountEdit({ update: true, delete: true }))).toBe(true);
    });

    it('hides the delete control when the user cannot delete the consultation', () => {
        expect(hasDeleteControl(mountEdit({ update: true, delete: false }))).toBe(false);
    });

    it('forwards the linked inactive medication into the treatment picker options', () => {
        const wrapper = mountEdit({ update: true, delete: true }, [
            {
                medication: {
                    uuid: 'med-discontinued',
                    name: 'Discontinuado',
                    presentation: 'Cápsula',
                    concentration: '250 mg',
                    dispensing_unit: 'cápsula',
                    is_active: false,
                },
                quantity_dispensed: 1,
                dose: '250 mg',
                frequency: 'c/12h',
                duration: '5 días',
            },
        ]);

        const options = wrapper.findComponent(ComboboxField).props('options') as { value: string; label: string }[];

        expect(options).toContainEqual({
            value: 'med-discontinued',
            label: 'Discontinuado · Cápsula 250 mg · inactivo',
        });
        expect(options).toContainEqual({
            value: 'med-paracetamol',
            label: 'Paracetamol · Tableta 500 mg · 120 disponibles',
        });
    });
});
