import { mount } from '@vue/test-utils';
import { Users } from '@lucide/vue';
import { defineComponent, h } from 'vue';
import { describe, expect, it, vi } from 'vitest';
import SidebarProvider from '@/components/ui/sidebar/SidebarProvider.vue';
import type { NavGroup } from '@/lib/navigation';

vi.mock('@inertiajs/vue3', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@inertiajs/vue3')>();

    return {
        ...actual,
        Link: defineComponent({
            name: 'InertiaLinkStub',
            props: { href: { type: String, required: true } },
            setup:
                (props, { slots }) =>
                () =>
                    h('a', { href: props.href, 'data-inertia-link': '' }, slots.default?.()),
        }),
    };
});

import NavMain from './NavMain.vue';

/** `NavMain`'s buttons call `useSidebar()`, which requires a `SidebarProvider` ancestor. */
function mountNavMain(groups: NavGroup[]) {
    return mount(SidebarProvider, {
        slots: {
            default: () => h(NavMain, { groups }),
        },
    });
}

describe('NavMain', () => {
    it('renders a group label only for groups that define one', () => {
        const groups: NavGroup[] = [
            { items: [{ title: 'Pacientes', href: '#', icon: Users, permission: 'patients.view' }] },
            {
                label: 'ADMINISTRACIÓN GENERAL',
                items: [{ title: 'Usuarios', href: '/users', icon: Users, permission: 'users.manage' }],
            },
        ];

        const wrapper = mountNavMain(groups);

        const labels = wrapper.findAll('[data-slot="sidebar-group-label"]');
        expect(labels).toHaveLength(1);
        expect(labels[0].text()).toBe('ADMINISTRACIÓN GENERAL');
    });

    it('renders a `#` item as a plain link with no disabled state, aria-disabled, or badge', () => {
        const groups: NavGroup[] = [
            { items: [{ title: 'Pacientes', href: '#', icon: Users, permission: 'patients.view' }] },
        ];

        const wrapper = mountNavMain(groups);
        const link = wrapper.get('a[href="#"]');

        expect(link.attributes('data-inertia-link')).toBeUndefined();
        expect(link.attributes('disabled')).toBeUndefined();
        expect(link.attributes('aria-disabled')).toBeUndefined();
        expect(wrapper.text()).not.toContain('Próximamente');
    });

    it('renders an item with a real route through the Inertia Link component', () => {
        const groups: NavGroup[] = [
            { items: [{ title: 'Consultas', href: '/dashboard', icon: Users, permission: 'consultations.view' }] },
        ];

        const wrapper = mountNavMain(groups);
        const link = wrapper.get('a[data-inertia-link]');

        expect(link.attributes('href')).toBe('/dashboard');
    });
});
