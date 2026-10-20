/* eslint-disable vue/one-component-per-file -- minimal inline test doubles for Inertia's Head and Link. */
import { mount, VueWrapper } from '@vue/test-utils';
import { defineComponent, h } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { index as dashboard } from '@/actions/App/Http/Controllers/DashboardController';
import { edit } from '@/actions/App/Http/Controllers/MedicalConsultationController';
import type { ConsultationAggregate, ConsultationFlash, PatientProfile } from '@/types/consultations';

const { pageState, toastSuccess } = vi.hoisted(() => ({
    pageState: { flash: {} as { consultation?: ConsultationFlash } },
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
        voided_at: null,
        voided_by: null,
        void_reason: null,
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
        can: { update: true, void: true, restore: false, audit: false },
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
    beforeEach(() => {
        pageState.flash = {};
        toastSuccess.mockClear();
    });

    it.each([
        ['voided', 'Consulta CON-0009 anulada'],
        ['restored', 'Consulta CON-0009 restaurada'],
    ] as const)('toasts the %s flash after redirecting to the detail page', (action, message) => {
        pageState.flash = { consultation: { uuid: 'consultation-uuid-9', code: 'CON-0009', action } };

        mountShow();

        expect(toastSuccess).toHaveBeenCalledWith(message);
    });

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
        const editable = mountShow({ can: { update: true, void: true, restore: false, audit: false } });
        const editLink = editable.findAll('a').find((link) => link.text() === 'Editar');
        expect(editLink?.attributes('href')).toBe(edit.url('consultation-uuid-9'));

        const readOnly = mountShow({ can: { update: false, void: true, restore: false, audit: false } });
        expect(readOnly.findAll('a').some((link) => link.text() === 'Editar')).toBe(false);
    });

    it('hides the edit link for a voided consultation even when the user can update', () => {
        const wrapper = mountShow({
            voided_at: '2026-09-02T09:00:00Z',
            can: { update: true, void: false, restore: true, audit: false },
        });

        expect(wrapper.findAll('a').some((link) => link.text() === 'Editar')).toBe(false);
    });

    it('never offers a delete control on the detail page', () => {
        const wrapper = mountShow({ can: { update: true, void: true, restore: false, audit: false } });

        expect(wrapper.text()).not.toContain('Eliminar');
    });

    describe('void, restore and history controls', () => {
        const voidedAt = '2026-09-02T09:00:00Z';

        function buttonLabels(wrapper: VueWrapper): string[] {
            return wrapper.findAll('button').map((button) => button.text());
        }

        it('offers voiding only when the user can void an active consultation', () => {
            const allowed = mountShow({ can: { update: true, void: true, restore: false, audit: false } });
            expect(buttonLabels(allowed)).toContain('Anular consulta');

            const denied = mountShow({ can: { update: true, void: false, restore: false, audit: false } });
            expect(buttonLabels(denied)).not.toContain('Anular consulta');
        });

        it('shows no banner for an active consultation', () => {
            const wrapper = mountShow();

            expect(wrapper.find('[data-testid="voided-banner"]').exists()).toBe(false);
            expect(buttonLabels(wrapper)).not.toContain('Restaurar consulta');
        });

        it('shows the banner and restore, but no edit or void, for a voided consultation', () => {
            const wrapper = mountShow({
                voided_at: voidedAt,
                voided_by: { name: 'Dr. Soto' },
                void_reason: 'Registro duplicado',
                can: { update: true, void: true, restore: true, audit: false },
            });

            const banner = wrapper.get('[data-testid="voided-banner"]');
            expect(banner.text()).toContain('Anulada');
            expect(banner.text()).toContain('Dr. Soto');
            expect(banner.text()).toContain('Registro duplicado');
            expect(buttonLabels(wrapper)).toContain('Restaurar consulta');
            expect(buttonLabels(wrapper)).not.toContain('Anular consulta');
            expect(wrapper.findAll('a').some((link) => link.text() === 'Editar')).toBe(false);
        });

        it('hides restore from users who cannot restore', () => {
            const wrapper = mountShow({
                voided_at: voidedAt,
                voided_by: { name: 'Dr. Soto' },
                void_reason: 'Registro duplicado',
                can: { update: false, void: false, restore: false, audit: false },
            });

            expect(buttonLabels(wrapper)).not.toContain('Restaurar consulta');
        });

        it('shows the history button only with the audit ability', () => {
            const audited = mountShow({ can: { update: true, void: true, restore: false, audit: true } });
            expect(buttonLabels(audited)).toContain('Historial de cambios');

            const plain = mountShow({ can: { update: true, void: true, restore: false, audit: false } });
            expect(buttonLabels(plain)).not.toContain('Historial de cambios');
        });
    });
});
