import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { formatDateTime } from '@/lib/formatDateTime';
import VoidedBanner from './VoidedBanner.vue';

describe('VoidedBanner.vue', () => {
    it('shows the "Anulada" title with date, user and reason', () => {
        const wrapper = mount(VoidedBanner, {
            props: { voidedAt: '2026-09-02T09:00:00Z', voidedBy: 'Dra. Ruiz', reason: 'Registro duplicado' },
        });

        expect(wrapper.text()).toContain('Anulada');
        expect(wrapper.text()).toContain(formatDateTime('2026-09-02T09:00:00Z'));
        expect(wrapper.text()).toContain('Dra. Ruiz');
        expect(wrapper.text()).toContain('Motivo: Registro duplicado');
    });

    it('omits the user and reason when they are not available', () => {
        const wrapper = mount(VoidedBanner, {
            props: { voidedAt: '2026-09-02T09:00:00Z', voidedBy: null, reason: null },
        });

        expect(wrapper.text()).not.toContain(' por ');
        expect(wrapper.text()).not.toContain('Motivo');
    });
});
