import { router } from '@inertiajs/core';
import { mount, VueWrapper } from '@vue/test-utils';
import { defineComponent, h, nextTick } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { index as dashboard } from '@/actions/App/Http/Controllers/DashboardController';
import { store, update } from '@/actions/App/Http/Controllers/MedicalConsultationController';
import ComboboxField from '@/components/form/ComboboxField.vue';
import type {
    ConsultationAggregate,
    ConsultationFormPayload,
    ConsultationPatient,
    MedicationOption,
} from '@/types/consultations';

/**
 * `ConsultationForm.vue` uses Inertia's REAL `useForm` here: only `Link` is
 * replaced (with a plain anchor) so no router/page context is needed. Submits
 * are intercepted at `@inertiajs/core`'s `router`, which is the exact object
 * the real form dispatches to, so `transform()`, `processing`, and the real
 * `onError` handler (`form.clearErrors().setError(errors)`) all run unchanged.
 */
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

import ConsultationForm from './ConsultationForm.vue';

type VisitOptions = {
    onStart?: (visit: unknown) => void;
    onError?: (errors: Record<string, string>) => void;
    onFinish?: (visit: unknown) => void;
};

const patient: ConsultationPatient = {
    uuid: 'patient-uuid-1',
    full_name: 'Ana López Pérez',
    enrollment_number: 'A-100',
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
    {
        uuid: 'med-ibuprofeno',
        name: 'Ibuprofeno',
        presentation: 'Tableta',
        concentration: '400 mg',
        dispensing_unit: 'tableta',
        current_stock: 40,
        is_active: true,
    },
];

const formOptions = {
    medicalStateOptions: [1, 2, 3, 4, 5],
    medicalClassificationOptions: [1, 2, 3],
    transferTypeOptions: [1, 2, 3],
    medicationOptions,
};

function existingConsultation(overrides: Partial<ConsultationAggregate> = {}): ConsultationAggregate {
    return {
        uuid: 'consultation-uuid-9',
        code: 'CON-0009',
        created_at: '2026-09-01T10:00:00Z',
        patient,
        physician: { name: 'Dra. Ruiz' },
        consultation: { current_condition: 'Cefalea', diagnosis: 'Migraña' },
        condition: 1,
        prognosis: 2,
        medical_classification: 3,
        treatment: [
            {
                medication: {
                    uuid: 'med-paracetamol',
                    name: 'Paracetamol',
                    presentation: 'Tableta',
                    concentration: '500 mg',
                    dispensing_unit: 'tableta',
                    is_active: true,
                },
                quantity_dispensed: 2,
                dose: '500 mg',
                frequency: 'c/8h',
                duration: '3 días',
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
            neurological: 'Normal',
            head_neck: 'Normal',
            thorax_cardiopulmonary: 'Normal',
            abdomen: 'Normal',
            extremities: 'Normal',
            cabinet_laboratory: 'Normal',
        },
        regulation: {
            transfer_type: 2,
            regulated_at: '2026-09-01T11:00',
            ambulance_registration: 'AMB-1',
            regulation_number: 'REG-1',
            clinic_id: 'CL-1',
            receiver_physician: 'Dr. Soto',
        },
        can: { update: true, delete: true },
        ...overrides,
    };
}

function mountCreateForm(slots: Record<string, () => unknown> = {}): VueWrapper {
    return mount(ConsultationForm, {
        props: { ...formOptions, mode: 'create', title: 'Nueva consulta', patient },
        slots,
    });
}

function mountEditForm(consultation: ConsultationAggregate = existingConsultation()): VueWrapper {
    return mount(ConsultationForm, {
        props: { ...formOptions, mode: 'edit', title: `Editar ${consultation.code}`, patient, consultation },
    });
}

function buttonByText(wrapper: VueWrapper, text: string) {
    const button = wrapper.findAll('button').find((candidate) => candidate.text().trim() === text);
    if (!button) {
        throw new Error(`No button found with text "${text}"`);
    }
    return button;
}

function fieldTextFor(wrapper: VueWrapper, controlSelector: string): string {
    const field = wrapper.get(controlSelector).element.closest('[data-slot="field"]');
    if (!field) {
        throw new Error(`No field wrapper found for "${controlSelector}"`);
    }
    return field.textContent ?? '';
}

async function submit(wrapper: VueWrapper) {
    await wrapper.get('form').trigger('submit');
}

describe('ConsultationForm.vue', () => {
    let postSpy: ReturnType<typeof vi.spyOn>;
    let putSpy: ReturnType<typeof vi.spyOn>;

    beforeEach(() => {
        postSpy = vi.spyOn(router, 'post').mockImplementation(() => {});
        putSpy = vi.spyOn(router, 'put').mockImplementation(() => {});
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    function lastPayload(spy: ReturnType<typeof vi.spyOn>): ConsultationFormPayload {
        return spy.mock.calls.at(-1)?.[1] as ConsultationFormPayload;
    }

    it('renders the header, every section, and the before-sections slot content', () => {
        const wrapper = mountCreateForm({ 'before-sections': () => h('div', { 'data-testid': 'profile-slot' }) });

        expect(wrapper.get('h1').text()).toBe('Nueva consulta');
        expect(wrapper.text()).toContain('Ana López Pérez');
        expect(wrapper.text()).toContain('#A-100');
        expect(wrapper.find('[data-testid="profile-slot"]').exists()).toBe(true);
        for (const section of [
            'Consulta',
            'Signos vitales',
            'Exploración física',
            'Tratamiento',
            'Regulación médica',
        ]) {
            expect(wrapper.findAll('[data-slot="card-title"]').map((title) => title.text())).toContain(section);
        }
    });

    it('shows the no-enrollment label in the header when the patient has no enrollment number', () => {
        const wrapper = mount(ConsultationForm, {
            props: {
                ...formOptions,
                mode: 'create',
                title: 'Nueva consulta',
                patient: { ...patient, enrollment_number: null },
            },
        });

        expect(wrapper.text()).toContain('Sin inscripción');
    });

    it('links Cancelar back to the dashboard', () => {
        const wrapper = mountCreateForm();

        const cancelLink = wrapper.findAll('a').find((link) => link.text() === 'Cancelar');
        expect(cancelLink?.attributes('href')).toBe(dashboard.url());
    });

    it('adds and removes treatment rows', async () => {
        const wrapper = mountCreateForm();

        expect(wrapper.text()).toContain('No hay medicamentos agregados.');
        expect(wrapper.text()).toContain('0 de 20 medicamentos.');

        await buttonByText(wrapper, 'Agregar medicamento').trigger('click');
        await buttonByText(wrapper, 'Agregar medicamento').trigger('click');
        expect(wrapper.findAll('[aria-label^="Medicamento "]')).toHaveLength(2);
        expect(wrapper.text()).toContain('2 de 20 medicamentos.');

        await wrapper.get('#treatment-1-dose').setValue('Cada 8 horas');
        await wrapper.get('button[aria-label="Quitar medicamento 1"]').trigger('click');

        expect(wrapper.findAll('[aria-label^="Medicamento "]')).toHaveLength(1);
        expect(wrapper.get<HTMLInputElement>('#treatment-0-dose').element.value).toBe('Cada 8 horas');
    });

    it('disables adding treatment rows at the 20-row limit and shows the limit hint', async () => {
        const wrapper = mountCreateForm();
        const addButton = buttonByText(wrapper, 'Agregar medicamento');

        for (let row = 0; row < 19; row++) {
            await addButton.trigger('click');
        }
        expect(addButton.attributes('disabled')).toBeUndefined();
        expect(wrapper.text()).not.toContain('Alcanzaste el máximo');

        await addButton.trigger('click');
        await addButton.trigger('click');

        expect(wrapper.findAll('[aria-label^="Medicamento "]')).toHaveLength(20);
        expect(addButton.attributes('disabled')).toBeDefined();
        expect(wrapper.text()).toContain('Alcanzaste el máximo de 20 medicamentos.');
    });

    it('submits an empty treatment list when no medication was added', async () => {
        const wrapper = mountCreateForm();

        await submit(wrapper);

        expect(postSpy).toHaveBeenCalledTimes(1);
        expect(lastPayload(postSpy).treatment).toEqual([]);
    });

    it('shows the available stock in the medication combobox option labels', async () => {
        const wrapper = mountCreateForm();
        await buttonByText(wrapper, 'Agregar medicamento').trigger('click');

        const options = wrapper.findComponent(ComboboxField).props('options') as { label: string }[];

        expect(options.map((option) => option.label)).toEqual([
            'Paracetamol · Tableta 500 mg · 120 disponibles',
            'Ibuprofeno · Tableta 400 mg · 40 disponibles',
        ]);
    });

    it('treats a medication option missing is_active as active', async () => {
        const optionWithoutIsActive = { ...medicationOptions[0] } as Partial<MedicationOption>;
        delete optionWithoutIsActive.is_active;
        const wrapper = mount(ConsultationForm, {
            props: {
                ...formOptions,
                medicationOptions: [optionWithoutIsActive as MedicationOption],
                mode: 'create',
                title: 'Nueva consulta',
                patient,
            },
        });
        await buttonByText(wrapper, 'Agregar medicamento').trigger('click');

        const options = wrapper.findComponent(ComboboxField).props('options') as { label: string }[];

        expect(options[0].label).toBe('Paracetamol · Tableta 500 mg · 120 disponibles');
    });

    it('excludes unlinked inactive medications from the create form picker', async () => {
        const wrapper = mountCreateForm();
        await buttonByText(wrapper, 'Agregar medicamento').trigger('click');

        const options = wrapper.findComponent(ComboboxField).props('options') as { label: string }[];

        expect(options.some((option) => option.label.includes('inactivo'))).toBe(false);
    });

    it('includes the linked inactive medication in the edit form picker', () => {
        const consultation = existingConsultation({
            treatment: [
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
            ],
        });
        const wrapper = mountEditForm(consultation);

        const options = wrapper.findComponent(ComboboxField).props('options') as { value: string; label: string }[];

        expect(options).toContainEqual({
            value: 'med-discontinued',
            label: 'Discontinuado · Cápsula 250 mg · inactivo',
        });
    });

    it('selects a medication via the combobox and submits the flat payload shape', async () => {
        const wrapper = mountCreateForm();
        await buttonByText(wrapper, 'Agregar medicamento').trigger('click');
        await wrapper.get('#treatment-0-quantity_dispensed').setValue('3');
        await wrapper.get('#treatment-0-dose').setValue('500 mg');
        await wrapper.get('#treatment-0-frequency').setValue('c/8h');
        await wrapper.get('#treatment-0-duration').setValue('3 días');

        await wrapper.findComponent(ComboboxField).setValue('med-ibuprofeno');
        await submit(wrapper);

        expect(lastPayload(postSpy).treatment).toEqual([
            {
                medication_uuid: 'med-ibuprofeno',
                quantity_dispensed: 3,
                dose: '500 mg',
                frequency: 'c/8h',
                duration: '3 días',
            },
        ]);
    });

    it('shows the regulation fields only while the transfer switch is on', async () => {
        const wrapper = mountCreateForm();
        const regulationSwitch = wrapper.get('#regulation-enabled');

        expect(regulationSwitch.attributes('aria-checked')).toBe('false');
        expect(wrapper.find('#transfer-type').exists()).toBe(false);

        await regulationSwitch.trigger('click');
        expect(regulationSwitch.attributes('aria-checked')).toBe('true');
        expect(wrapper.find('#transfer-type').exists()).toBe(true);
        expect(wrapper.find('#regulation-regulated-at').exists()).toBe(true);
        expect(wrapper.find('#regulation-receiver_physician').exists()).toBe(true);

        await regulationSwitch.trigger('click');
        expect(wrapper.find('#transfer-type').exists()).toBe(false);
    });

    it('creates via POST to the store URL with the patient uuid, null regulation, and null empty glucose', async () => {
        const wrapper = mountCreateForm();

        await wrapper.get('#diagnosis').setValue('Faringitis');
        await wrapper.get('#vital-weight').setValue('68');
        await submit(wrapper);

        expect(postSpy).toHaveBeenCalledTimes(1);
        expect(putSpy).not.toHaveBeenCalled();
        const [url, payload] = postSpy.mock.calls[0] as [string, ConsultationFormPayload];
        expect(url).toBe(store.url());
        expect(payload.patient_uuid).toBe('patient-uuid-1');
        expect(payload.consultation.diagnosis).toBe('Faringitis');
        expect(payload.vital_signs.weight).toBe(68);
        expect(payload.vital_signs.glucose).toBeNull();
        expect(payload.regulation).toBeNull();
    });

    it('sends the regulation object when the transfer switch is on', async () => {
        const wrapper = mountCreateForm();

        await wrapper.get('#regulation-enabled').trigger('click');
        await wrapper.get('#transfer-type').setValue('3');
        await wrapper.get('#regulation-regulated-at').setValue('2026-09-20T08:30');
        await wrapper.get('#regulation-receiver_physician').setValue('Dr. Soto');
        await wrapper.get('#vital-glucose').setValue('110');
        await submit(wrapper);

        const payload = lastPayload(postSpy);
        expect(payload.regulation).toEqual({
            transfer_type: 3,
            regulated_at: '2026-09-20T08:30',
            ambulance_registration: '',
            regulation_number: '',
            clinic_id: '',
            receiver_physician: 'Dr. Soto',
        });
        expect(payload.vital_signs.glucose).toBe(110);
    });

    it('updates via PUT to the update URL without a patient uuid, with the flat treatment payload', async () => {
        const consultation = existingConsultation();
        const wrapper = mountEditForm(consultation);

        expect(wrapper.get<HTMLTextAreaElement>('#diagnosis').element.value).toBe('Migraña');
        expect(wrapper.get('#regulation-enabled').attributes('aria-checked')).toBe('true');

        await submit(wrapper);

        expect(putSpy).toHaveBeenCalledTimes(1);
        expect(postSpy).not.toHaveBeenCalled();
        const [url, payload] = putSpy.mock.calls[0] as [string, ConsultationFormPayload];
        expect(url).toBe(update.url(consultation.uuid));
        expect(payload).not.toHaveProperty('patient_uuid');
        expect(payload.regulation).toEqual(consultation.regulation);
        expect(payload.treatment).toEqual([
            {
                medication_uuid: 'med-paracetamol',
                quantity_dispensed: 2,
                dose: '500 mg',
                frequency: 'c/8h',
                duration: '3 días',
            },
        ]);
    });

    it('sends null regulation on edit once the transfer switch is turned off', async () => {
        const wrapper = mountEditForm();

        await wrapper.get('#regulation-enabled').trigger('click');
        await submit(wrapper);

        expect(lastPayload(putSpy).regulation).toBeNull();
    });

    it('renders dotted server errors under their fields after a failed submit replaces form.errors', async () => {
        postSpy.mockImplementation((_url: string, _data: unknown, options: VisitOptions) => {
            options.onError?.({
                'consultation.diagnosis': 'El diagnóstico es obligatorio.',
                'treatment.0.dose': 'La dosis es obligatoria.',
                'vital_signs.blood_pressure_diastolic': 'La TA diastólica es obligatoria.',
                'regulation.transfer_type': 'El tipo de traslado es obligatorio.',
            });
            options.onFinish?.({});
        });
        const wrapper = mountCreateForm();
        await buttonByText(wrapper, 'Agregar medicamento').trigger('click');
        await wrapper.get('#regulation-enabled').trigger('click');

        await submit(wrapper);
        await nextTick();

        expect(fieldTextFor(wrapper, '#diagnosis')).toContain('El diagnóstico es obligatorio.');
        expect(fieldTextFor(wrapper, '#treatment-0-dose')).toContain('La dosis es obligatoria.');
        expect(fieldTextFor(wrapper, '#vital-blood_pressure_diastolic')).toContain('La TA diastólica es obligatoria.');
        expect(fieldTextFor(wrapper, '#transfer-type')).toContain('El tipo de traslado es obligatorio.');
        expect(fieldTextFor(wrapper, '#current-condition')).not.toContain('obligatori');
        expect(wrapper.get('#diagnosis').attributes('aria-invalid')).toBe('true');
    });

    it('maps treatment.N.quantity_dispensed server errors to the matching row only', async () => {
        postSpy.mockImplementation((_url: string, _data: unknown, options: VisitOptions) => {
            options.onError?.({
                'treatment.0.quantity_dispensed': 'No hay suficiente inventario disponible.',
                'treatment.1.quantity_dispensed': 'La cantidad es obligatoria.',
            });
            options.onFinish?.({});
        });
        const wrapper = mountCreateForm();
        await buttonByText(wrapper, 'Agregar medicamento').trigger('click');
        await buttonByText(wrapper, 'Agregar medicamento').trigger('click');

        await submit(wrapper);
        await nextTick();

        expect(fieldTextFor(wrapper, '#treatment-0-quantity_dispensed')).toContain(
            'No hay suficiente inventario disponible.',
        );
        expect(fieldTextFor(wrapper, '#treatment-1-quantity_dispensed')).toContain('La cantidad es obligatoria.');
        expect(fieldTextFor(wrapper, '#treatment-0-quantity_dispensed')).not.toContain('La cantidad es obligatoria.');
        expect(fieldTextFor(wrapper, '#treatment-1-quantity_dispensed')).not.toContain(
            'No hay suficiente inventario disponible.',
        );
    });

    it('issues only one submit while the first one is still processing', async () => {
        postSpy.mockImplementation((_url: string, _data: unknown, options: VisitOptions) => {
            options.onStart?.({});
        });
        const wrapper = mountCreateForm();

        await submit(wrapper);
        await submit(wrapper);
        await submit(wrapper);

        expect(postSpy).toHaveBeenCalledTimes(1);
        const savingButton = buttonByText(wrapper, 'Guardando…');
        expect(savingButton.attributes('disabled')).toBeDefined();
    });
});
