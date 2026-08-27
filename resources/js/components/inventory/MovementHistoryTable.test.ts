import { mount } from '@vue/test-utils';
import { defineComponent, h } from 'vue';
import { describe, expect, it, vi } from 'vitest';
import type { InventoryMovement, Paginator } from '@/types/inventory';

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

import MovementHistoryTable from './MovementHistoryTable.vue';

function movement(overrides: Partial<InventoryMovement> = {}): InventoryMovement {
    return {
        id: 1,
        type: 1,
        quantity: 10,
        stock_after: 110,
        medical_consultation_code: null,
        user: 'Ana López',
        notes: null,
        occurred_at: '2026-08-20T15:30:00+00:00',
        ...overrides,
    };
}

function paginator(
    data: InventoryMovement[],
    links: Paginator<InventoryMovement>['links'] = [],
): Paginator<InventoryMovement> {
    return { data, links };
}

describe('MovementHistoryTable.vue', () => {
    it.each([
        [1, 'Entrada'],
        [2, 'Dispensación'],
        [3, 'Ajuste'],
        [4, 'Reversión de dispensación'],
    ] as const)('labels movement type %i as "%s"', (type, label) => {
        const table = mount(MovementHistoryTable, {
            props: { movements: paginator([movement({ type })]) },
        });

        expect(table.text()).toContain(label);
    });

    it('shows a positive quantity with a leading "+"', () => {
        const table = mount(MovementHistoryTable, {
            props: { movements: paginator([movement({ quantity: 10 })]) },
        });

        expect(table.text()).toContain('+10');
    });

    it('shows a negative quantity as-is', () => {
        const table = mount(MovementHistoryTable, {
            props: { movements: paginator([movement({ type: 2, quantity: -3 })]) },
        });

        expect(table.text()).toContain('-3');
    });

    it('renders the stock after the movement', () => {
        const table = mount(MovementHistoryTable, {
            props: { movements: paginator([movement({ stock_after: 87 })]) },
        });

        expect(table.text()).toContain('87');
    });

    it('shows the consultation code when the movement is linked to one', () => {
        const table = mount(MovementHistoryTable, {
            props: {
                movements: paginator([movement({ type: 2, medical_consultation_code: 'CONS-0042' })]),
            },
        });

        expect(table.text()).toContain('CONS-0042');
    });

    it('shows an em dash for the consultation column when there is no linked consultation', () => {
        const table = mount(MovementHistoryTable, {
            props: { movements: paginator([movement({ medical_consultation_code: null })]) },
        });

        const row = table.get('tbody tr');
        expect(row.text()).toContain('—');
    });

    it('shows an em dash for the user column when the movement has no attributed user', () => {
        const table = mount(MovementHistoryTable, {
            props: { movements: paginator([movement({ user: null })]) },
        });

        const row = table.get('tbody tr');
        expect(row.text()).toContain('—');
    });

    it('shows an em dash for the notes column when the movement has no notes', () => {
        const table = mount(MovementHistoryTable, {
            props: { movements: paginator([movement({ notes: null })]) },
        });

        const row = table.get('tbody tr');
        expect(row.text()).toContain('—');
    });

    it('renders the notes when present', () => {
        const table = mount(MovementHistoryTable, {
            props: { movements: paginator([movement({ notes: 'Recepción de proveedor' })]) },
        });

        expect(table.text()).toContain('Recepción de proveedor');
    });

    it('renders pagination links and highlights the active page', () => {
        const table = mount(MovementHistoryTable, {
            props: {
                movements: paginator(
                    [movement()],
                    [
                        { url: null, label: '&laquo; Previous', active: false },
                        { url: '/inventory/med-1?page=1', label: '1', active: true },
                        { url: '/inventory/med-1?page=2', label: '2', active: false },
                        { url: '/inventory/med-1?page=2', label: 'Next &raquo;', active: false },
                    ],
                ),
            },
        });

        const pageOneLink = table.findAll('a').find((link) => link.text() === '1');
        expect(pageOneLink?.classes()).toContain('bg-muted');
        expect(table.text()).toContain('Previous');
    });

    it('does not render pagination when there are three or fewer links', () => {
        const table = mount(MovementHistoryTable, {
            props: {
                movements: paginator(
                    [movement()],
                    [
                        { url: null, label: '&laquo; Previous', active: false },
                        { url: '/inventory/med-1?page=1', label: '1', active: true },
                        { url: null, label: 'Next &raquo;', active: false },
                    ],
                ),
            },
        });

        expect(table.find('nav').exists()).toBe(false);
    });

    it('shows the empty state when there are no movements', () => {
        const table = mount(MovementHistoryTable, {
            props: { movements: paginator([]) },
        });

        expect(table.text()).toContain('No se encontraron movimientos.');
    });
});
