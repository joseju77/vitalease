/* eslint-disable vue/one-component-per-file -- minimal inline test doubles for Inertia's Head and Link. */
import { mount, VueWrapper } from '@vue/test-utils';
import { defineComponent, h } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { activate } from '@/routes/inventory';
import type { InventoryMovement, Medication, MedicationFlash, Paginator } from '@/types/inventory';

import MedicationActionDialog from '@/components/inventory/MedicationActionDialog.vue';
import MedicationFormDialog from '@/components/inventory/MedicationFormDialog.vue';
import StockAdjustmentDialog from '@/components/inventory/StockAdjustmentDialog.vue';
import StockEntryDialog from '@/components/inventory/StockEntryDialog.vue';

const { pageState, routerGet, routerPatch } = vi.hoisted(() => ({
    pageState: {
        flash: {} as { medication?: MedicationFlash },
        props: { auth: { is_super_admin: false, permissions: [] as string[] } },
    },
    routerGet: vi.fn(),
    routerPatch: vi.fn(),
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
        router: { get: routerGet, patch: routerPatch },
    };
});

/** The persistent layout is applied by Inertia, not by a direct mount; stubbing it skips its asset imports. */
vi.mock('@/layouts/AppLayout.vue', () => ({ default: {} }));

const { toastSuccess } = vi.hoisted(() => ({ toastSuccess: vi.fn() }));
vi.mock('vue-sonner', () => ({ toast: { success: toastSuccess, error: vi.fn() } }));

import Show from './Show.vue';

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

function movements(data: InventoryMovement[] = [movement()]): Paginator<InventoryMovement> {
    return { data, links: [] };
}

let wrapper: VueWrapper | null = null;

function mountShow(props: { medication: Medication; movements: Paginator<InventoryMovement> }): VueWrapper {
    wrapper = mount(Show, { props });
    return wrapper;
}

describe('Pages/inventory/Show.vue', () => {
    beforeEach(() => {
        pageState.flash = {};
        pageState.props.auth = { is_super_admin: false, permissions: [] };
        routerGet.mockClear();
        routerPatch.mockClear();
        toastSuccess.mockClear();
    });

    afterEach(() => {
        wrapper?.unmount();
        wrapper = null;
    });

    it('titles the browser tab with the medication name', () => {
        const page = mountShow({ medication: medication(), movements: movements() });

        expect(page.findComponent({ name: 'InertiaHead' }).props('title')).toBe('Paracetamol');
        expect(page.get('h1').text()).toBe('Paracetamol');
    });

    it('renders the detail fields', () => {
        const page = mountShow({ medication: medication(), movements: movements() });

        expect(page.text()).toContain('Tableta');
        expect(page.text()).toContain('500 mg');
        expect(page.text()).toContain('tableta');
        expect(page.text()).toContain('100');
        expect(page.text()).toContain('20');
    });

    it('shows a destructive "Stock bajo" badge only when the medication is low on stock', () => {
        const low = mountShow({ medication: medication({ is_low_stock: true }), movements: movements() });
        expect(low.text()).toContain('Stock bajo');
        low.unmount();

        const normal = mountShow({ medication: medication({ is_low_stock: false }), movements: movements() });
        expect(normal.text()).not.toContain('Stock bajo');
    });

    it('shows a secondary "Inactivo" badge only when the medication is inactive', () => {
        const inactive = mountShow({ medication: medication({ is_active: false }), movements: movements() });
        expect(inactive.text()).toContain('Inactivo');
        inactive.unmount();

        const active = mountShow({ medication: medication({ is_active: true }), movements: movements() });
        expect(active.text()).not.toContain('Inactivo');
    });

    it('renders the movement history table', () => {
        const page = mountShow({
            medication: medication(),
            movements: movements([movement({ notes: 'Recepción de proveedor' })]),
        });

        expect(page.text()).toContain('Historial de movimientos');
        expect(page.text()).toContain('Recepción de proveedor');
    });

    it('links back to the inventory index', () => {
        const page = mountShow({ medication: medication(), movements: movements() });

        const backLink = page.findAll('a').find((link) => link.text().includes('Volver al inventario'));
        expect(backLink?.attributes('href')).toBe('/inventory');
    });

    it('hides "Editar" without inventory.update', () => {
        const page = mountShow({ medication: medication(), movements: movements() });

        expect(page.findAll('button').some((button) => button.text() === 'Editar')).toBe(false);
    });

    it('shows "Editar" with inventory.update and opens the form dialog in edit mode', async () => {
        pageState.props.auth.permissions = ['inventory.update'];
        const page = mountShow({ medication: medication(), movements: movements() });

        const editButton = page.findAll('button').find((button) => button.text() === 'Editar');
        await editButton?.trigger('click');

        const dialog = page.findComponent(MedicationFormDialog);
        expect(dialog.exists()).toBe(true);
        expect(dialog.props('mode')).toBe('edit');
        expect(dialog.props('open')).toBe(true);
    });

    it('shows "Desactivar" (not "Activar") for an active medication with inventory.update', () => {
        pageState.props.auth.permissions = ['inventory.update'];
        const page = mountShow({ medication: medication({ is_active: true }), movements: movements() });

        const buttonTexts = page.findAll('button').map((button) => button.text());
        expect(buttonTexts).toContain('Desactivar');
        expect(buttonTexts).not.toContain('Activar');
    });

    it('shows "Activar" (not "Desactivar") for an inactive medication with inventory.update', () => {
        pageState.props.auth.permissions = ['inventory.update'];
        const page = mountShow({ medication: medication({ is_active: false }), movements: movements() });

        const buttonTexts = page.findAll('button').map((button) => button.text());
        expect(buttonTexts).toContain('Activar');
        expect(buttonTexts).not.toContain('Desactivar');
    });

    it('hides both "Activar" and "Desactivar" without inventory.update', () => {
        const page = mountShow({ medication: medication({ is_active: true }), movements: movements() });

        const buttonTexts = page.findAll('button').map((button) => button.text());
        expect(buttonTexts).not.toContain('Activar');
        expect(buttonTexts).not.toContain('Desactivar');
    });

    it('PATCHes the activate URL directly (no confirmation) when "Activar" is clicked', async () => {
        pageState.props.auth.permissions = ['inventory.update'];
        const page = mountShow({ medication: medication({ is_active: false }), movements: movements() });

        const activateButton = page.findAll('button').find((button) => button.text() === 'Activar');
        await activateButton?.trigger('click');

        expect(routerPatch).toHaveBeenCalledTimes(1);
        expect(routerPatch).toHaveBeenCalledWith(activate('med-1').url, {}, { preserveScroll: true });
        expect(page.findComponent(MedicationActionDialog).exists()).toBe(false);
    });

    it('opens the MedicationActionDialog in "deactivate" mode when "Desactivar" is clicked', async () => {
        pageState.props.auth.permissions = ['inventory.update'];
        const page = mountShow({ medication: medication({ is_active: true }), movements: movements() });

        const deactivateButton = page.findAll('button').find((button) => button.text() === 'Desactivar');
        await deactivateButton?.trigger('click');

        const dialog = page.findComponent(MedicationActionDialog);
        expect(dialog.exists()).toBe(true);
        expect(dialog.props('action')).toBe('deactivate');
        expect(dialog.props('open')).toBe(true);
    });

    it('hides "Eliminar" without inventory.delete even when can_be_deleted is true', () => {
        const page = mountShow({ medication: medication({ can_be_deleted: true }), movements: movements() });

        expect(page.findAll('button').some((button) => button.text() === 'Eliminar')).toBe(false);
    });

    it('hides "Eliminar" when can_be_deleted is false, even with inventory.delete', () => {
        pageState.props.auth.permissions = ['inventory.delete'];
        const page = mountShow({ medication: medication({ can_be_deleted: false }), movements: movements() });

        expect(page.findAll('button').some((button) => button.text() === 'Eliminar')).toBe(false);
    });

    it('shows "Eliminar" and opens the action dialog in "delete" mode with inventory.delete and can_be_deleted true', async () => {
        pageState.props.auth.permissions = ['inventory.delete'];
        const page = mountShow({ medication: medication({ can_be_deleted: true }), movements: movements() });

        const deleteButton = page.findAll('button').find((button) => button.text() === 'Eliminar');
        expect(deleteButton).toBeTruthy();
        await deleteButton?.trigger('click');

        const dialog = page.findComponent(MedicationActionDialog);
        expect(dialog.exists()).toBe(true);
        expect(dialog.props('action')).toBe('delete');
        expect(dialog.props('open')).toBe(true);
    });

    it('hides "Registrar entrada" without inventory.create', () => {
        const page = mountShow({ medication: medication(), movements: movements() });

        expect(page.findAll('button').some((button) => button.text() === 'Registrar entrada')).toBe(false);
    });

    it('shows "Registrar entrada" with inventory.create and opens the entry dialog', async () => {
        pageState.props.auth.permissions = ['inventory.create'];
        const page = mountShow({ medication: medication(), movements: movements() });

        const entryButton = page.findAll('button').find((button) => button.text() === 'Registrar entrada');
        expect(entryButton).toBeTruthy();
        await entryButton?.trigger('click');

        const dialog = page.findComponent(StockEntryDialog);
        expect(dialog.exists()).toBe(true);
        expect(dialog.props('open')).toBe(true);
    });

    it('hides "Registrar ajuste" without inventory.update', () => {
        const page = mountShow({ medication: medication(), movements: movements() });

        expect(page.findAll('button').some((button) => button.text() === 'Registrar ajuste')).toBe(false);
    });

    it('shows "Registrar ajuste" with inventory.update and opens the adjustment dialog', async () => {
        pageState.props.auth.permissions = ['inventory.update'];
        const page = mountShow({ medication: medication(), movements: movements() });

        const adjustmentButton = page.findAll('button').find((button) => button.text() === 'Registrar ajuste');
        expect(adjustmentButton).toBeTruthy();
        await adjustmentButton?.trigger('click');

        const dialog = page.findComponent(StockAdjustmentDialog);
        expect(dialog.exists()).toBe(true);
        expect(dialog.props('open')).toBe(true);
    });

    it('toasts the medication flash on mount', () => {
        pageState.flash = { medication: { uuid: 'med-1', name: 'Paracetamol', action: 'updated' } };

        mountShow({ medication: medication(), movements: movements() });

        expect(toastSuccess).toHaveBeenCalledWith('Medicamento Paracetamol actualizado');
    });
});
