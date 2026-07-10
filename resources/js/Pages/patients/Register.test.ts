/* eslint-disable vue/one-component-per-file -- this file defines several minimal inline test-double
   components for Register.vue's real children; splitting each into its own file would scatter
   throwaway test fixtures across the tree for no readability gain. */
import { flushPromises, mount, VueWrapper } from '@vue/test-utils';
import { defineComponent, h, reactive, type PropType } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type {
    PatientAilment,
    PatientContactInformation,
    PatientDemographics,
    PatientEmergencyContact,
    PatientGynecologicalHistory,
    PatientOtherAilments,
    PatientRegistrationMetadata,
    PatientRegistrationPayload,
} from '@/types/patients';

/**
 * `Register.vue` reads `useForm`/`usePage`/`Head` from `@inertiajs/vue3`. This
 * fake `useForm` mirrors just enough of Inertia's real contract for these
 * tests: reactive top-level data keys (so nested `v-model` mutation on
 * `form.patient` etc. behaves like the real thing), `errors`, `processing`,
 * chainable `transform()`, and a `post()` that synchronously flips
 * `processing` (matching Inertia's real behavior) and records the call
 * instead of hitting the network.
 */
const { formState, postSpy, pageState } = vi.hoisted(() => {
    return {
        formState: { current: null as Record<string, unknown> | null },
        postSpy: vi.fn(),
        pageState: { flash: {} as Record<string, unknown> },
    };
});

vi.mock('@inertiajs/vue3', () => {
    return {
        Head: defineComponent({
            props: { title: { type: String, default: '' } },
            setup: () => () => null,
        }),
        usePage: () => ({ flash: pageState.flash }),
        useForm: (initialData: Record<string, unknown>) => {
            const dataKeys = Object.keys(initialData);
            let pendingTransform: ((data: Record<string, unknown>) => unknown) | null = null;

            const form = reactive({
                ...structuredClone(initialData),
                errors: {} as Record<string, string>,
                processing: false,
                transform(callback: (data: Record<string, unknown>) => unknown) {
                    pendingTransform = callback;
                    return form;
                },
                post(url: string, options?: Record<string, unknown>) {
                    form.processing = true;
                    const rawData: Record<string, unknown> = {};
                    for (const key of dataKeys) {
                        rawData[key] = (form as Record<string, unknown>)[key];
                    }
                    const payload = pendingTransform ? pendingTransform(rawData) : rawData;
                    postSpy(url, payload, options);
                },
            });

            formState.current = form;
            return form;
        },
    };
});

import Register from './Register.vue';
import ContactStep from '@/components/patients/ContactStep.vue';

function createMetadataProps(): PatientRegistrationMetadata {
    return {
        sexAtBirthOptions: [1, 2],
        maritalStatusOptions: [1, 2, 3, 4],
        bloodTypeOptions: [1, 2],
        kinshipTypeOptions: [1, 2],
        ailmentTypeOptions: [1, 2],
        contraceptiveMethodOptions: [1, 2],
        familyMedicalUnits: [],
        enrollments: [],
    };
}

/** Minimal test double for `DemographicsStep.vue`: only exposes the two fields these tests drive. */
const DemographicsStepStub = defineComponent({
    name: 'DemographicsStep',
    props: {
        modelValue: { type: Object as () => PatientDemographics, required: true },
        sexAtBirthOptions: { type: Array as () => number[], default: () => [] },
        maritalStatusOptions: { type: Array as () => number[], default: () => [] },
        bloodTypeOptions: { type: Array as () => number[], default: () => [] },
        familyMedicalUnits: { type: Array, default: () => [] },
        enrollments: { type: Array, default: () => [] },
        errors: { type: Object as () => Partial<Record<string, string>>, default: () => ({}) },
    },
    emits: ['update:modelValue'],
    setup(props, { emit }) {
        return () =>
            h('div', { 'data-testid': 'demographics-step' }, [
                h('input', {
                    'data-testid': 'first-name-input',
                    value: props.modelValue.first_name,
                    onInput: (event: Event) => {
                        emit('update:modelValue', {
                            ...props.modelValue,
                            first_name: (event.target as HTMLInputElement).value,
                        });
                    },
                }),
                h('span', { 'data-testid': 'first-name-error' }, props.errors?.['patient.first_name'] ?? ''),
                h(
                    'select',
                    {
                        'data-testid': 'sex-at-birth-select',
                        value: props.modelValue.sex_at_birth === null ? '' : String(props.modelValue.sex_at_birth),
                        onChange: (event: Event) => {
                            const value = (event.target as HTMLSelectElement).value;
                            emit('update:modelValue', {
                                ...props.modelValue,
                                sex_at_birth: value === '' ? null : Number(value),
                            });
                        },
                    },
                    [
                        h('option', { value: '' }, '-'),
                        ...props.sexAtBirthOptions.map((option) =>
                            h('option', { value: String(option) }, String(option)),
                        ),
                    ],
                ),
            ]);
    },
});

const ContactStepStub = defineComponent({
    name: 'ContactStep',
    props: {
        modelValue: { type: Object as () => PatientContactInformation, required: true },
        errors: { type: Object as () => Partial<Record<string, string>>, default: () => ({}) },
    },
    emits: ['update:modelValue'],
    setup: () => () => h('div', { 'data-testid': 'contact-step' }),
});

const EmergencyContactsStepStub = defineComponent({
    name: 'EmergencyContactsStep',
    props: {
        modelValue: { type: Array as () => PatientEmergencyContact[], required: true },
        kinshipTypeOptions: { type: Array, default: () => [] },
        errors: { type: Object as () => Partial<Record<string, string>>, default: () => ({}) },
    },
    emits: ['update:modelValue'],
    setup: () => () => h('div', { 'data-testid': 'emergency-contacts-step' }),
});

const AilmentsStepStub = defineComponent({
    name: 'AilmentsStep',
    props: {
        ailments: { type: Array as () => PatientAilment[], required: true },
        /** `required: false` so Vue's runtime prop validator does not reject the real `null` value this field carries by default. */
        otherAilments: { type: Object as unknown as PropType<PatientOtherAilments | null>, default: null },
        ailmentTypeOptions: { type: Array, default: () => [] },
        errors: { type: Object as () => Partial<Record<string, string>>, default: () => ({}) },
    },
    emits: ['update:ailments', 'update:otherAilments'],
    setup: () => () => h('div', { 'data-testid': 'ailments-step' }),
});

const GynecologicalHistoryStepStub = defineComponent({
    name: 'GynecologicalHistoryStep',
    props: {
        modelValue: { type: Object as () => PatientGynecologicalHistory, required: true },
        contraceptiveMethodOptions: { type: Array, default: () => [] },
        errors: { type: Object as () => Partial<Record<string, string>>, default: () => ({}) },
    },
    emits: ['update:modelValue'],
    setup: () => () => h('div', { 'data-testid': 'gynecological-history-step' }),
});

function mountRegister(): VueWrapper {
    return mount(Register, {
        props: createMetadataProps(),
        global: {
            stubs: {
                DemographicsStep: DemographicsStepStub,
                ContactStep: ContactStepStub,
                EmergencyContactsStep: EmergencyContactsStepStub,
                AilmentsStep: AilmentsStepStub,
                GynecologicalHistoryStep: GynecologicalHistoryStepStub,
            },
        },
    });
}

function findButtonByText(wrapper: VueWrapper, text: string) {
    const button = wrapper.findAll('button').find((candidate) => candidate.text().trim() === text);
    if (!button) {
        throw new Error(`No button found with text "${text}"`);
    }
    return button;
}

async function goNext(wrapper: VueWrapper) {
    await findButtonByText(wrapper, 'Siguiente').trigger('click');
}

async function goBack(wrapper: VueWrapper) {
    await findButtonByText(wrapper, 'Atrás').trigger('click');
}

async function selectSexAtBirth(wrapper: VueWrapper, value: string) {
    await wrapper.get('[data-testid="sex-at-birth-select"]').setValue(value);
}

describe('Register.vue', () => {
    beforeEach(() => {
        postSpy.mockClear();
        pageState.flash = {};
        formState.current = null;
    });

    it('moves stepIndex forward and backward without submitting', async () => {
        const wrapper = mountRegister();

        expect(wrapper.text()).toContain('Paso 1 de 4');
        expect(wrapper.find('[data-testid="demographics-step"]').exists()).toBe(true);

        await goNext(wrapper);
        expect(wrapper.text()).toContain('Paso 2 de 4');
        expect(wrapper.find('[data-testid="contact-step"]').exists()).toBe(true);

        await goBack(wrapper);
        expect(wrapper.text()).toContain('Paso 1 de 4');
        expect(wrapper.find('[data-testid="demographics-step"]').exists()).toBe(true);

        expect(postSpy).not.toHaveBeenCalled();
    });

    it('retains values entered in an earlier step when navigating away and back', async () => {
        const wrapper = mountRegister();

        await wrapper.get('[data-testid="first-name-input"]').setValue('Ana María');

        await goNext(wrapper);
        await goNext(wrapper);
        expect(wrapper.text()).toContain('Paso 3 de 4');

        await goBack(wrapper);
        await goBack(wrapper);
        expect(wrapper.text()).toContain('Paso 1 de 4');
        expect(wrapper.get<HTMLInputElement>('[data-testid="first-name-input"]').element.value).toBe('Ana María');
    });

    it('adds the gynecological history step and grows the step count when Female is selected', async () => {
        const wrapper = mountRegister();

        expect(wrapper.text()).toContain('Paso 1 de 4');

        await selectSexAtBirth(wrapper, '2');
        expect(wrapper.text()).toContain('Paso 1 de 5');

        await goNext(wrapper);
        await goNext(wrapper);
        await goNext(wrapper);
        await goNext(wrapper);
        expect(wrapper.text()).toContain('Paso 5 de 5: Historial ginecológico');
        expect(wrapper.find('[data-testid="gynecological-history-step"]').exists()).toBe(true);
    });

    it('removes the gynecological history step when Male is selected', async () => {
        const wrapper = mountRegister();

        await selectSexAtBirth(wrapper, '2');
        expect(wrapper.text()).toContain('Paso 1 de 5');

        await selectSexAtBirth(wrapper, '1');
        expect(wrapper.text()).toContain('Paso 1 de 4');
    });

    it('clamps stepIndex back into range when Female is deselected while on the removed last step', async () => {
        const wrapper = mountRegister();

        await selectSexAtBirth(wrapper, '2');
        await goNext(wrapper);
        await goNext(wrapper);
        await goNext(wrapper);
        await goNext(wrapper);
        expect(wrapper.text()).toContain('Paso 5 de 5');

        const form = formState.current as { patient: PatientDemographics };
        form.patient.sex_at_birth = 1;
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('Paso 4 de 4: Padecimientos');
        expect(wrapper.find('[data-testid="gynecological-history-step"]').exists()).toBe(false);
    });

    it('omits gynecological_history from the submitted payload for a non-Female patient', async () => {
        const wrapper = mountRegister();

        await goNext(wrapper);
        await goNext(wrapper);
        await goNext(wrapper);
        expect(wrapper.text()).toContain('Paso 4 de 4');

        await findButtonByText(wrapper, 'Enviar registro').trigger('click');

        expect(postSpy).toHaveBeenCalledTimes(1);
        const [url, payload] = postSpy.mock.calls[0] as [string, PatientRegistrationPayload];
        expect(url).toBe('/patients/register');
        expect(payload).not.toHaveProperty('gynecological_history');
    });

    it('includes gynecological_history in the submitted payload for a Female patient', async () => {
        const wrapper = mountRegister();

        await selectSexAtBirth(wrapper, '2');
        await goNext(wrapper);
        await goNext(wrapper);
        await goNext(wrapper);
        await goNext(wrapper);
        expect(wrapper.text()).toContain('Paso 5 de 5');

        await findButtonByText(wrapper, 'Enviar registro').trigger('click');

        expect(postSpy).toHaveBeenCalledTimes(1);
        const [, payload] = postSpy.mock.calls[0] as [string, PatientRegistrationPayload];
        expect(payload).toHaveProperty('gynecological_history');
    });

    it('renders a dotted-path server error at its owning step', async () => {
        const wrapper = mountRegister();

        const form = formState.current as { errors: Record<string, string> };
        form.errors = { 'patient.first_name': 'Este campo es obligatorio.' };
        await wrapper.vm.$nextTick();

        expect(wrapper.get('[data-testid="first-name-error"]').text()).toBe('Este campo es obligatorio.');
    });

    it('disables the submit control while form.processing is true', async () => {
        const wrapper = mountRegister();

        await goNext(wrapper);
        await goNext(wrapper);
        await goNext(wrapper);

        const submitButton = findButtonByText(wrapper, 'Enviar registro');
        expect(submitButton.attributes('disabled')).toBeUndefined();

        await submitButton.trigger('click');

        expect((formState.current as { processing: boolean }).processing).toBe(true);
        expect(wrapper.text()).toContain('Enviando...');
        expect(findButtonByText(wrapper, 'Enviando...').attributes('disabled')).toBeDefined();
    });

    it('issues only one POST when the submit control is clicked repeatedly', async () => {
        const wrapper = mountRegister();

        await goNext(wrapper);
        await goNext(wrapper);
        await goNext(wrapper);

        await findButtonByText(wrapper, 'Enviar registro').trigger('click');
        await findButtonByText(wrapper, 'Enviando...').trigger('click');
        await findButtonByText(wrapper, 'Enviando...').trigger('click');

        expect(postSpy).toHaveBeenCalledTimes(1);
    });

    it('renders the one-time success alert when the registrationSuccess flash is true', () => {
        pageState.flash = { registrationSuccess: true };
        const wrapper = mountRegister();

        expect(wrapper.text()).toContain('Registro completado con éxito. Gracias por registrar al paciente.');
    });

    it('does not render the success alert when the registrationSuccess flash is absent', () => {
        pageState.flash = {};
        const wrapper = mountRegister();

        expect(wrapper.text()).not.toContain('Registro completado con éxito');
    });
});

describe('ContactStep.vue neighborhood lookup', () => {
    type FetchCall = {
        url: string;
        signal: AbortSignal;
        resolve: (value: { ok: boolean; json: () => Promise<unknown> }) => void;
        reject: (reason: unknown) => void;
    };

    let pendingFetches: FetchCall[];
    let fetchMock: ReturnType<typeof vi.fn>;

    function createContactInformation(overrides: Partial<PatientContactInformation> = {}): PatientContactInformation {
        return reactive({
            address: '',
            phone_number: '',
            personal_email: '',
            institutional_email: null,
            zip_code: null,
            neighborhood_id: null,
            ...overrides,
        }) as PatientContactInformation;
    }

    function mountContactStep(modelValue: PatientContactInformation) {
        return mount(ContactStep, { props: { modelValue } });
    }

    async function setZip(wrapper: VueWrapper, value: string) {
        await wrapper.get('input[autocomplete="postal-code"]').setValue(value);
    }

    function resolveFetch(index: number, body: unknown, ok = true) {
        pendingFetches[index].resolve({ ok, json: async () => body });
    }

    beforeEach(() => {
        vi.useFakeTimers();
        pendingFetches = [];
        fetchMock = vi.fn((url: string, init?: RequestInit) => {
            return new Promise((resolve, reject) => {
                const signal = init?.signal as AbortSignal;
                pendingFetches.push({ url, signal, resolve, reject });
                signal.addEventListener('abort', () => {
                    const abortError = new Error('Aborted');
                    abortError.name = 'AbortError';
                    reject(abortError);
                });
            });
        });
        vi.stubGlobal('fetch', fetchMock);
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.unstubAllGlobals();
    });

    it('fires exactly one lookup request 300ms after a valid 5-digit zip is entered', async () => {
        const wrapper = mountContactStep(createContactInformation());

        await setZip(wrapper, '44100');
        await vi.advanceTimersByTimeAsync(300);

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(pendingFetches[0].url).toContain('zip_code=44100');
    });

    it('populates the neighborhood select and enables it once the response resolves', async () => {
        const contactInformation = createContactInformation();
        const wrapper = mountContactStep(contactInformation);

        expect(wrapper.get('#contact-neighborhood').attributes('disabled')).toBeDefined();

        await setZip(wrapper, '44100');
        await vi.advanceTimersByTimeAsync(300);
        resolveFetch(0, [
            { id: 12, name: 'Centro' },
            { id: 13, name: 'Americana' },
        ]);
        await flushPromises();

        expect(wrapper.get('#contact-neighborhood').attributes('disabled')).toBeUndefined();
    });

    it('does not fire a lookup for an incomplete zip', async () => {
        const wrapper = mountContactStep(createContactInformation());

        await setZip(wrapper, '441');
        await vi.advanceTimersByTimeAsync(300);

        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('discards a stale in-flight response when a newer zip supersedes it', async () => {
        const contactInformation = createContactInformation({ neighborhood_id: 12 });
        const wrapper = mountContactStep(contactInformation);

        await setZip(wrapper, '44100');
        await vi.advanceTimersByTimeAsync(300);
        expect(fetchMock).toHaveBeenCalledTimes(1);
        const firstRequestSignal = pendingFetches[0].signal;

        await setZip(wrapper, '11000');
        await vi.advanceTimersByTimeAsync(300);
        expect(fetchMock).toHaveBeenCalledTimes(2);

        expect(firstRequestSignal.aborted).toBe(true);

        resolveFetch(1, [{ id: 90, name: 'Roma Norte' }]);
        await flushPromises();

        expect(contactInformation.neighborhood_id).toBeNull();
        expect(wrapper.get('#contact-neighborhood').attributes('disabled')).toBeUndefined();
    });

    it('renders a non-blocking empty state when the response is an empty array', async () => {
        const wrapper = mountContactStep(createContactInformation());

        await setZip(wrapper, '99999');
        await vi.advanceTimersByTimeAsync(300);
        resolveFetch(0, []);
        await flushPromises();

        expect(wrapper.text()).toContain('No se encontraron colonias para este código postal.');
        expect(wrapper.get<HTMLInputElement>('input[autocomplete="postal-code"]').element.value).toBe('99999');
    });

    it('renders a non-blocking empty state when the lookup request fails', async () => {
        const wrapper = mountContactStep(createContactInformation());

        await setZip(wrapper, '44100');
        await vi.advanceTimersByTimeAsync(300);
        pendingFetches[0].reject(new Error('network error'));
        await flushPromises();

        expect(wrapper.text()).toContain('No se encontraron colonias para este código postal.');
    });
});
