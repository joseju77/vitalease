import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import SearchDegradedNotice from './SearchDegradedNotice.vue';

describe('SearchDegradedNotice.vue', () => {
    it('announces the basic-results mode as a polite status in Spanish', () => {
        const notice = mount(SearchDegradedNotice);

        expect(notice.get('[role="status"]').exists()).toBe(true);
        expect(notice.text()).toContain('El buscador avanzado no está disponible');
    });

    it('documents the fallback limits: no typo correction and no accent folding', () => {
        const notice = mount(SearchDegradedNotice);

        expect(notice.text()).toContain('no corrige errores de escritura');
        expect(notice.text()).toContain('ni distingue acentos');
    });
});
