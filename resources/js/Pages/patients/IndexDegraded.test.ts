/* eslint-disable vue/one-component-per-file -- minimal inline test doubles for Inertia's Head and Link. */
import { mount, VueWrapper } from '@vue/test-utils';
import { defineComponent, h } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const { pageState } = vi.hoisted(() => ({
    pageState: { props: { search_degraded: false } as { search_degraded?: boolean } },
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

vi.mock('@/layouts/AppLayout.vue', () => ({ default: {} }));

import Index from './Index.vue';

let wrapper: VueWrapper | null = null;

function mountIndex(): VueWrapper {
    wrapper = mount(Index, {
        props: { patients: { data: [], links: [] }, filters: { q: 'ana', status: 'active' } },
    });
    return wrapper;
}

describe('Pages/patients/Index.vue degraded search notice', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        wrapper?.unmount();
        wrapper = null;
        vi.useRealTimers();
    });

    it('shows the degraded search notice when the shared search_degraded prop is true', () => {
        pageState.props = { search_degraded: true };

        expect(mountIndex().find('[data-testid="search-degraded-notice"]').exists()).toBe(true);
    });

    it('hides the notice when the search engine answered', () => {
        pageState.props = { search_degraded: false };

        expect(mountIndex().find('[data-testid="search-degraded-notice"]').exists()).toBe(false);
    });

    it('hides the notice when the prop is absent', () => {
        pageState.props = {};

        expect(mountIndex().find('[data-testid="search-degraded-notice"]').exists()).toBe(false);
    });
});
