/* eslint-disable vue/one-component-per-file -- minimal inline test doubles for Inertia's Head and Link. */
import { mount, VueWrapper } from '@vue/test-utils';
import { defineComponent, h } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { index, show } from '@/routes/inventory';
import type { Medication, MedicationFlash, Paginator } from '@/types/inventory';

const { pageState, routerGet } = vi.hoisted(() => ({
    pageState: {
        flash: {} as { medication?: MedicationFlash },
        props: { auth: { is_super_admin: false, permissions: [] as string[] } },
    },
    routerGet: vi.fn(),
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
        router: { get: routerGet },
    };
});

/** The persistent layout is applied by Inertia, not by a direct mount; stubbing it skips its asset imports. */
vi.mock('@/layouts/AppLayout.vue', () => ({ default: {} }));

const { toastSuccess } = vi.hoisted(() => ({ toastSuccess: vi.fn() }));
vi.mock('vue-sonner', () => ({ toast: { success: toastSuccess } }));

import Index from './Index.vue';

function medication(overrides: Partial<Medication> = {}): Medication {
    return {
        uuid: 'med-1',
        name: 'Paracetamol',
        presentation: 'Tableta',
        concentration: '500 mg',
        dispensing_unit: 'tableta',
        current_stock: 100,
        minimum_stock: 20,
        is_active: true,
        is_low_stock: false,
        can_be_deleted: true,
        ...overrides,
    };
}

function paginator(data: Medication[], links: Paginator<Medication>['links'] = []): Paginator<Medication> {
    return { data, links };
}

let wrapper: VueWrapper | null = null;

function mountIndex(
    medications: Paginator<Medication>,
    filters: { q: string | null; low_stock: boolean } = { q: null, low_stock: false },
): VueWrapper {
    wrapper = mount(Index, { props: { medications, filters } });
    return wrapper;
}

describe('Pages/inventory/Index.vue', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        pageState.flash = {};
        pageState.props.auth = { is_super_admin: false, permissions: [] };
        routerGet.mockClear();
        toastSuccess.mockClear();
    });

    afterEach(() => {
        wrapper?.unmount();
        wrapper = null;
        vi.useRealTimers();
    });

    it('titles the page Inventario', () => {
        const page = mountIndex(paginator([]));

        expect(page.get('h1').text()).toBe('Inventario');
        expect(page.findComponent({ name: 'InertiaHead' }).props('title')).toBe('Inventario');
    });

    it('renders a row per medication with its catalog data and links the name to its detail page', () => {
        const page = mountIndex(paginator([medication()]));

        expect(page.text()).toContain('Paracetamol');
        expect(page.text()).toContain('Tableta');
        expect(page.text()).toContain('500 mg');
        expect(page.text()).toContain('tableta');
        expect(page.text()).toContain('100 / 20');

        const nameLink = page.findAll('a').find((link) => link.text() === 'Paracetamol');
        expect(nameLink?.attributes('href')).toBe(show.url('med-1'));
    });

    it('shows a destructive "Stock bajo" badge only when the medication is low on stock', () => {
        const low = mountIndex(paginator([medication({ is_low_stock: true })]));
        expect(low.text()).toContain('Stock bajo');
        low.unmount();

        const normal = mountIndex(paginator([medication({ is_low_stock: false })]));
        expect(normal.text()).not.toContain('Stock bajo');
    });

    it('shows a secondary "Inactivo" badge only when the medication is inactive', () => {
        const inactive = mountIndex(paginator([medication({ is_active: false })]));
        expect(inactive.text()).toContain('Inactivo');
        inactive.unmount();

        const active = mountIndex(paginator([medication({ is_active: true })]));
        expect(active.text()).not.toContain('Inactivo');
    });

    it('shows the empty state when there are no medications', () => {
        const page = mountIndex(paginator([]));

        expect(page.text()).toContain('No se encontraron medicamentos.');
    });

    it('searches after a 300ms debounce, omitting an empty query', async () => {
        const page = mountIndex(paginator([medication()]));

        await page.get('input[type="search"]').setValue('para');
        await vi.advanceTimersByTimeAsync(299);
        expect(routerGet).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(1);
        expect(routerGet).toHaveBeenCalledTimes(1);
        expect(routerGet).toHaveBeenCalledWith(
            index().url,
            { q: 'para' },
            {
                preserveState: true,
                replace: true,
                preserveScroll: true,
            },
        );
    });

    it('trims the search term before sending it', async () => {
        const page = mountIndex(paginator([medication()]));

        await page.get('input[type="search"]').setValue('  para  ');
        await vi.advanceTimersByTimeAsync(300);

        expect(routerGet).toHaveBeenCalledWith(index().url, { q: 'para' }, expect.anything());
    });

    it('does not search for an empty or whitespace-only query', async () => {
        const page = mountIndex(paginator([medication()]));

        await page.get('input[type="search"]').setValue('   ');
        await vi.advanceTimersByTimeAsync(300);

        expect(routerGet).toHaveBeenCalledWith(index().url, {}, expect.anything());
    });

    it('toggles the low-stock filter after the debounce, omitting it when unchecked', async () => {
        const page = mountIndex(paginator([medication()]));

        await page.get('#inventory-low-stock-filter').trigger('click');
        await vi.advanceTimersByTimeAsync(300);

        expect(routerGet).toHaveBeenCalledWith(index().url, { low_stock: true }, expect.anything());

        routerGet.mockClear();
        await page.get('#inventory-low-stock-filter').trigger('click');
        await vi.advanceTimersByTimeAsync(300);

        expect(routerGet).toHaveBeenCalledWith(index().url, {}, expect.anything());
    });

    it('renders pagination links and highlights the active page', () => {
        const page = mountIndex(
            paginator(
                [medication()],
                [
                    { url: null, label: '&laquo; Previous', active: false },
                    { url: '/inventory?page=1', label: '1', active: true },
                    { url: '/inventory?page=2', label: '2', active: false },
                    { url: '/inventory?page=2', label: 'Next &raquo;', active: false },
                ],
            ),
        );

        const pageOneLink = page.findAll('a').find((link) => link.text() === '1');
        expect(pageOneLink?.classes()).toContain('bg-muted');
        expect(page.text()).toContain('Previous');
    });

    it('toasts the medication flash on mount', () => {
        pageState.flash = { medication: { uuid: 'med-1', name: 'Paracetamol', action: 'created' } };

        mountIndex(paginator([medication()]));

        expect(toastSuccess).toHaveBeenCalledWith('Medicamento Paracetamol creado');
    });

    it('hides the "Nuevo medicamento" button without inventory.create', () => {
        pageState.props.auth.permissions = [];
        const page = mountIndex(paginator([medication()]));

        expect(page.findAll('button').some((button) => button.text() === 'Nuevo medicamento')).toBe(false);
    });

    it('shows the "Nuevo medicamento" button with inventory.create', () => {
        pageState.props.auth.permissions = ['inventory.create'];
        const page = mountIndex(paginator([medication()]));

        expect(page.findAll('button').some((button) => button.text() === 'Nuevo medicamento')).toBe(true);
    });

    it('hides the row "Editar" action without inventory.update', () => {
        pageState.props.auth.permissions = [];
        const page = mountIndex(paginator([medication()]));

        expect(page.findAll('button').some((button) => button.text() === 'Editar')).toBe(false);
    });

    it('shows the row "Editar" action with inventory.update', () => {
        pageState.props.auth.permissions = ['inventory.update'];
        const page = mountIndex(paginator([medication()]));

        expect(page.findAll('button').some((button) => button.text() === 'Editar')).toBe(true);
    });
});
