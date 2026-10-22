/* eslint-disable vue/one-component-per-file -- this file defines minimal inline test doubles for Edit.vue's
   real children (the step components) and for Inertia's Head and Link. */
import { mount, VueWrapper } from '@vue/test-utils';
import { defineComponent, h, reactive } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { emptyPatientAggregate } from '@/composables/usePatientAggregateForm';
import type { Neighborhood, PatientRegistrationMetadata, PatientRegistrationPayload } from '@/types/patients';

/**
 * Same fake `useForm` contract as `Register.test.ts`, with `put()` instead of
 * `post()`: reactive data keys, `errors`, `processing`, chainable `transform()`.
 */
const { formState, putSpy, pageState } = vi.hoisted(() => ({
    formState: { current: null as Record<string, unknown> | null },
    putSpy: vi.fn(),
    pageState: { flash: {} as Record<string, unknown> },
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({
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
    usePage: () => ({ flash: pageState.flash }),
    useForm: (initialData: Record<string, unknown>) => {
        const dataKeys = Object.keys(initialData);
        let pendingTransform: ((data: Record<string, unknown>) => unknown) | null = null;

        const form = reactive({
            ...JSON.parse(JSON.stringify(initialData)),
            errors: {} as Record<string, string>,
            processing: false,
            transform(callback: (data: Record<string, unknown>) => unknown) {
                pendingTransform = callback;
                return form;
            },
            put(url: string, options?: Record<string, unknown>) {
                form.processing = true;
                const rawData: Record<string, unknown> = {};
                for (const key of dataKeys) {
                    rawData[key] = (form as Record<string, unknown>)[key];
                }
                putSpy(url, pendingTransform ? pendingTransform(rawData) : rawData, options);
            },
        });

        formState.current = form;
        return form;
    },
}));

/** The persistent layout is applied by Inertia, not by a direct mount; stubbing it skips its asset imports. */
vi.mock('@/layouts/AppLayout.vue', () => ({ default: {} }));

import Edit from './Edit.vue';

/**
 * Probe double for a step component: renders its `errors` prop as JSON and
 * the model it received, so tests assert what the page hands each section.
 */
function stepProbe(name: string, testId: string) {
    return defineComponent({
        name,
        props: {
            modelValue: { type: [Object, Array], default: undefined },
            ailments: { type: Array, default: undefined },
            otherAilments: { type: Object, default: null },
            initialNeighborhoods: { type: Array, default: undefined },
            errors: { type: Object, default: () => ({}) },
        },
        setup: (props) => () =>
            h('div', { 'data-testid': testId }, [
                h('span', { 'data-testid': `${testId}-errors` }, JSON.stringify(props.errors)),
                h('span', { 'data-testid': `${testId}-model` }, JSON.stringify(props.modelValue ?? props.ailments)),
                h('span', { 'data-testid': `${testId}-neighborhoods` }, JSON.stringify(props.initialNeighborhoods)),
            ]),
    });
}

const stubs = {
    DemographicsStep: stepProbe('DemographicsStep', 'demographics-step'),
    ContactStep: stepProbe('ContactStep', 'contact-step'),
    EmergencyContactsStep: stepProbe('EmergencyContactsStep', 'emergency-step'),
    AilmentsStep: stepProbe('AilmentsStep', 'ailments-step'),
    GynecologicalHistoryStep: stepProbe('GynecologicalHistoryStep', 'gynecological-step'),
};

const metadata: PatientRegistrationMetadata = {
    sexAtBirthOptions: [1, 2],
    maritalStatusOptions: [1, 2],
    bloodTypeOptions: [1, 2],
    kinshipTypeOptions: [1, 2],
    ailmentTypeOptions: [1, 2],
    contraceptiveMethodOptions: [1, 2],
    familyMedicalUnits: [],
    enrollments: [],
};

const neighborhoods: Neighborhood[] = [
    { id: 12, name: 'Centro' },
    { id: 13, name: 'Americana' },
];

function storedAggregate(sexAtBirth: number): PatientRegistrationPayload {
    const aggregate = emptyPatientAggregate();
    aggregate.patient.first_name = 'Ana';
    aggregate.patient.last_name = 'López';
    aggregate.patient.sex_at_birth = sexAtBirth;
    aggregate.contact_information.address = 'Calle 1';
    aggregate.contact_information.zip_code = '44100';
    aggregate.contact_information.neighborhood_id = 12;
    aggregate.emergency_contacts = [{ name: 'Luis López', phone_number: '+520987654321', kinship_type: 1 }];
    aggregate.ailments = [{ ailment_type: 1, diagnosed_at: '2020-01-01', treatment_notes: null }];

    return aggregate;
}

function mountEdit(sexAtBirth = 1): VueWrapper {
    return mount(Edit, {
        props: {
            ...metadata,
            patient: { uuid: 'patient-uuid-1', full_name: 'Ana López' },
            form: storedAggregate(sexAtBirth),
            neighborhoods,
        },
        global: { stubs },
    });
}

function setErrors(errors: Record<string, string>): void {
    (formState.current as { errors: Record<string, string> }).errors = errors;
}

function submitButton(wrapper: VueWrapper) {
    return wrapper.get('button[type="submit"]');
}

describe('Pages/patients/Edit.vue', () => {
    beforeEach(() => {
        putSpy.mockClear();
        pageState.flash = {};
        formState.current = null;
    });

    it('stacks the section cards for the personal, contact, emergency and ailment data', () => {
        const page = mountEdit();

        expect(page.get('h1').text()).toBe('Editar paciente');
        expect(page.text()).toContain('Ana López');
        for (const section of ['demographics', 'contact', 'emergency', 'ailments']) {
            expect(page.find(`[data-testid="edit-section-${section}"]`).exists()).toBe(true);
        }
        expect(
            page
                .findAll('[data-testid^="edit-section-"] h3, [data-testid^="edit-section-"] [data-slot="card-title"]')
                .map((title) => title.text()),
        ).toEqual(['Datos personales', 'Datos de contacto', 'Contactos de emergencia', 'Padecimientos']);
    });

    it('prefills every section with the stored aggregate', () => {
        const page = mountEdit();

        expect(page.get('[data-testid="demographics-step-model"]').text()).toContain('"first_name":"Ana"');
        expect(page.get('[data-testid="contact-step-model"]').text()).toContain('"address":"Calle 1"');
        expect(page.get('[data-testid="contact-step-model"]').text()).toContain('"neighborhood_id":12');
        expect(page.get('[data-testid="emergency-step-model"]').text()).toContain('Luis López');
        expect(page.get('[data-testid="ailments-step-model"]').text()).toContain('2020-01-01');
    });

    it('seeds the contact section with the neighborhoods of the stored zip code', () => {
        const page = mountEdit();

        expect(JSON.parse(page.get('[data-testid="contact-step-neighborhoods"]').text())).toEqual(neighborhoods);
    });

    it('shows the gynecological section only for a female patient', () => {
        expect(mountEdit(1).find('[data-testid="edit-section-gynecological"]').exists()).toBe(false);
        expect(mountEdit(2).find('[data-testid="edit-section-gynecological"]').exists()).toBe(true);
    });

    it('submits one PUT to the patient update URL with the whole aggregate', async () => {
        const page = mountEdit(2);

        await page.get('form').trigger('submit');

        expect(putSpy).toHaveBeenCalledTimes(1);
        const [url, payload, options] = putSpy.mock.calls[0] as [string, PatientRegistrationPayload, object];
        expect(url).toBe('/patients/patient-uuid-1');
        expect(payload.patient.first_name).toBe('Ana');
        expect(payload.contact_information.neighborhood_id).toBe(12);
        expect(payload.emergency_contacts).toHaveLength(1);
        expect(payload.gynecological_history).not.toBeNull();
        expect(options).toMatchObject({ preserveScroll: true });
    });

    it('omits gynecological_history from the payload of a non-female patient', async () => {
        const page = mountEdit(1);

        await page.get('form').trigger('submit');

        expect(putSpy.mock.calls[0][1]).not.toHaveProperty('gynecological_history');
    });

    it('disables the submit button while saving', async () => {
        const page = mountEdit();
        expect(submitButton(page).text()).toBe('Guardar cambios');

        await page.get('form').trigger('submit');

        expect(submitButton(page).text()).toBe('Guardando...');
        expect(submitButton(page).attributes('disabled')).toBeDefined();
    });

    it('offers a Cancelar link back to the patient detail page', () => {
        const page = mountEdit();

        const cancel = page.findAll('a').find((link) => link.text() === 'Cancelar');
        expect(cancel?.attributes('href')).toBe('/patients/patient-uuid-1');
    });

    it('passes the server field errors, including duplicate errors, to the matching section', async () => {
        const page = mountEdit();

        setErrors({
            'patient.social_security_number': 'Este NSS ya está registrado.',
            'patient.enrollment_number': 'Esta inscripción ya está registrada.',
            'contact_information.personal_email': 'Este correo ya está registrado.',
            'emergency_contacts.0.phone_number': 'Teléfono inválido.',
        });
        await page.vm.$nextTick();

        const demographicsErrors = page.get('[data-testid="demographics-step-errors"]').text();
        expect(demographicsErrors).toContain('Este NSS ya está registrado.');
        expect(demographicsErrors).toContain('Esta inscripción ya está registrada.');
        expect(page.get('[data-testid="contact-step-errors"]').text()).toContain('Este correo ya está registrado.');
        expect(page.get('[data-testid="emergency-step-errors"]').text()).toContain('Teléfono inválido.');
    });

    it('flags only the sections that have errors with a review hint', async () => {
        const page = mountEdit();
        expect(page.findAll('[role="alert"]')).toHaveLength(0);

        setErrors({ 'contact_information.personal_email': 'Este correo ya está registrado.' });
        await page.vm.$nextTick();

        const hints = page.findAll('[role="alert"]');
        expect(hints).toHaveLength(1);
        expect(hints[0].text()).toBe('Revisa los campos marcados en esta sección.');
        expect(page.get('[data-testid="edit-section-contact"]').text()).toContain('Revisa los campos marcados');
        expect(page.get('[data-testid="edit-section-demographics"]').text()).not.toContain(
            'Revisa los campos marcados',
        );
    });

    it('flags the ailments section for both ailment and other-ailment errors', async () => {
        const page = mountEdit();

        setErrors({ 'other_ailments.allergies': 'Demasiado largo.' });
        await page.vm.$nextTick();

        expect(page.get('[data-testid="edit-section-ailments"]').text()).toContain('Revisa los campos marcados');
    });

    it('shows the patient state error as an alert without flagging the personal data section', async () => {
        const page = mountEdit();

        setErrors({ patient: 'El paciente está deshabilitado y no puede editarse.' });
        await page.vm.$nextTick();

        expect(page.get('[data-testid="edit-state-error"]').text()).toContain(
            'El paciente está deshabilitado y no puede editarse.',
        );
        expect(page.get('[data-testid="edit-section-demographics"]').text()).not.toContain(
            'Revisa los campos marcados',
        );
    });

    it('shows no state error alert when there are only field errors', async () => {
        const page = mountEdit();

        setErrors({ 'patient.first_name': 'Obligatorio.' });
        await page.vm.$nextTick();

        expect(page.find('[data-testid="edit-state-error"]').exists()).toBe(false);
        expect(page.get('[data-testid="edit-section-demographics"]').text()).toContain('Revisa los campos marcados');
    });
});
