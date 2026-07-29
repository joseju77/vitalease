import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import type { PatientProfile } from '@/types/consultations';
import PatientProfileCard from './PatientProfileCard.vue';

function minimalProfile(overrides: Partial<PatientProfile> = {}): PatientProfile {
    return {
        first_name: 'Ana',
        last_name: 'López',
        second_last_name: null,
        birth_date: '1990-05-10',
        age: 36,
        sex_at_birth: 1,
        marital_status: 1,
        blood_type: 7,
        enrollment: null,
        enrollment_number: null,
        external_enrollment: null,
        family_medical_unit: null,
        other_family_medical_unit: null,
        social_security_number: '12345678901',
        contact_information: null,
        emergency_contacts: [],
        ailments: [],
        other_ailments: null,
        gynecological_history: null,
        ...overrides,
    };
}

async function mountExpanded(profile: PatientProfile) {
    const wrapper = mount(PatientProfileCard, { props: { profile } });
    await wrapper.get('button[aria-expanded]').trigger('click');
    await flushPromises();
    return wrapper;
}

function tabLabels(wrapper: ReturnType<typeof mount>): string[] {
    return wrapper.findAll('[role="tab"]').map((tab) => tab.text());
}

describe('PatientProfileCard', () => {
    it('starts collapsed and toggles its content on demand', async () => {
        const wrapper = mount(PatientProfileCard, { props: { profile: minimalProfile() } });
        const toggle = wrapper.get('button[aria-expanded]');

        expect(toggle.attributes('aria-expanded')).toBe('false');
        expect(toggle.text()).toContain('Mostrar');
        expect(wrapper.text()).not.toContain('Ana López');
        expect(wrapper.findAll('[role="tab"]')).toHaveLength(0);

        await toggle.trigger('click');
        await flushPromises();
        expect(toggle.attributes('aria-expanded')).toBe('true');
        expect(toggle.text()).toContain('Ocultar');
        expect(wrapper.text()).toContain('Ana López');

        await toggle.trigger('click');
        await flushPromises();
        expect(toggle.attributes('aria-expanded')).toBe('false');
        expect(wrapper.text()).not.toContain('Ana López');
    });

    it('labels the general data and marks missing values as not recorded', async () => {
        const wrapper = await mountExpanded(minimalProfile());

        expect(wrapper.text()).toContain('Ana López');
        expect(wrapper.text()).toContain('Masculino');
        expect(wrapper.text()).toContain('Soltero(a)');
        expect(wrapper.text()).toContain('O+');
        expect(wrapper.text()).toContain('No registrado');
    });

    it('hides the gynecological history tab for a male patient without history', async () => {
        const wrapper = await mountExpanded(minimalProfile());

        expect(tabLabels(wrapper)).toEqual([
            'Datos generales',
            'Contacto',
            'Contactos de emergencia',
            'Padecimientos',
            'Otros padecimientos',
        ]);
    });

    it('shows the gynecological history tab for a female patient even without history', async () => {
        const wrapper = await mountExpanded(minimalProfile({ sex_at_birth: 2 }));

        expect(tabLabels(wrapper)).toContain('Historial ginecológico');
    });
});
