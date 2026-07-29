import { mount } from '@vue/test-utils';
import { defineComponent, h } from 'vue';
import { describe, expect, it, vi } from 'vitest';
import { show } from '@/actions/App/Http/Controllers/MedicalConsultationController';
import { formatDateTime } from '@/lib/formatDateTime';
import type { ConsultationListItem } from '@/types/consultations';

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

import ConsultationList from './ConsultationList.vue';

function consultationItem(overrides: Partial<ConsultationListItem> = {}): ConsultationListItem {
    return {
        uuid: 'consultation-uuid-1',
        code: 'CON-0001',
        created_at: '2026-09-01T10:00:00Z',
        diagnosis: 'Migraña',
        medical_classification: 1,
        patient: { uuid: 'patient-uuid-1', full_name: 'Ana López Pérez' },
        physician: { name: 'Dra. Ruiz' },
        can: { update: true, delete: true },
        ...overrides,
    };
}

describe('ConsultationList.vue', () => {
    it('renders one row per consultation with code, patient, classification label, date, and a detail link', () => {
        const consultations = [
            consultationItem(),
            consultationItem({
                uuid: 'consultation-uuid-2',
                code: 'CON-0002',
                medical_classification: 7,
                created_at: '2026-09-02T15:30:00Z',
                patient: { uuid: 'patient-uuid-2', full_name: 'Luis Gómez' },
            }),
        ];

        const wrapper = mount(ConsultationList, { props: { consultations } });

        const rows = wrapper.findAll('tbody tr');
        expect(rows).toHaveLength(2);
        const firstCells = rows[0].findAll('td').map((cell) => cell.text());
        expect(firstCells).toEqual([
            'CON-0001',
            'Ana López Pérez',
            'Traumatología',
            formatDateTime('2026-09-01T10:00:00Z'),
        ]);
        expect(rows[1].text()).toContain('Luis Gómez');
        expect(rows[1].text()).toContain('Dermatología');
        expect(rows[0].get('a').attributes('href')).toBe(show.url('consultation-uuid-1'));
        expect(rows[1].get('a').attributes('href')).toBe('/consultations/consultation-uuid-2');
    });

    it('shows the consultation count in the badge', () => {
        const wrapper = mount(ConsultationList, {
            props: { consultations: [consultationItem(), consultationItem({ uuid: 'consultation-uuid-2' })] },
        });

        const badge = wrapper.get('[aria-label="2 consultas"]');
        expect(badge.text()).toBe('2');
    });

    it('shows an empty state and a zero count without consultations', () => {
        const wrapper = mount(ConsultationList, { props: { consultations: [] } });

        expect(wrapper.text()).toContain('No tienes consultas registradas.');
        expect(wrapper.find('table').exists()).toBe(false);
        expect(wrapper.get('[aria-label="0 consultas"]').text()).toBe('0');
    });
});
