/* eslint-disable vue/one-component-per-file -- minimal inline test double for Inertia's Head. */
import { mount, VueWrapper } from '@vue/test-utils';
import { defineComponent } from 'vue';
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/vue3', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@inertiajs/vue3')>();

    return {
        ...actual,
        Head: defineComponent({
            name: 'InertiaHead',
            props: { title: { type: String, default: '' } },
            setup: () => () => null,
        }),
    };
});

import ServiceUnavailable from './ServiceUnavailable.vue';

let wrapper: VueWrapper | null = null;

describe('Pages/errors/ServiceUnavailable.vue', () => {
    afterEach(() => {
        wrapper?.unmount();
        wrapper = null;
        vi.restoreAllMocks();
    });

    it('renders the Spanish unavailable message and states that no data was modified', () => {
        wrapper = mount(ServiceUnavailable);

        expect(wrapper.get('h1').text()).toBe('Servicio temporalmente no disponible');
        expect(wrapper.text()).toContain('Tus datos no se modificaron');
        expect(wrapper.findComponent({ name: 'InertiaHead' }).props('title')).toBe('Servicio no disponible');
    });

    it('renders standalone, mounting without a page layout or shared auth props', () => {
        wrapper = mount(ServiceUnavailable);

        expect(wrapper.find('main').exists()).toBe(true);
        expect(wrapper.find('nav').exists()).toBe(false);
        expect(wrapper.find('aside').exists()).toBe(false);
    });

    it('reloads the page when the Reintentar button is pressed', async () => {
        const reload = vi.fn();
        vi.stubGlobal('location', { ...window.location, reload });
        wrapper = mount(ServiceUnavailable);

        const retry = wrapper.findAll('button').find((button) => button.text() === 'Reintentar');
        await retry?.trigger('click');

        expect(reload).toHaveBeenCalledTimes(1);
        vi.unstubAllGlobals();
    });
});
