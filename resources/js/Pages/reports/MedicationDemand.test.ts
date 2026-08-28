/* eslint-disable vue/one-component-per-file -- minimal inline test doubles for Inertia's Head and the chart component. */
import { mount, VueWrapper } from '@vue/test-utils';
import { defineComponent, h } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { medicationDemand } from '@/routes/reports';
import type { DemandProjection, MedicationSummary } from '@/types/inventory';

import ComboboxField from '@/components/form/ComboboxField.vue';

const { routerGet, chartPointsProp } = vi.hoisted(() => ({
    routerGet: vi.fn(),
    chartPointsProp: [] as unknown[],
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
        router: { get: routerGet },
    };
});

/** The persistent layout is applied by Inertia, not by a direct mount; stubbing it skips its asset imports. */
vi.mock('@/layouts/AppLayout.vue', () => ({ default: {} }));

/**
 * The chart's own rendering (Unovis series/axes/dashing) is covered by
 * `components/reports/DemandProjectionChart.test.ts`; this page only needs
 * to assert it receives the right merged points for a given projection.
 */
vi.mock('@/components/reports/DemandProjectionChart.vue', () => ({
    default: defineComponent({
        name: 'DemandProjectionChartStub',
        props: { points: { type: Array, default: () => [] } },
        setup: (props) => {
            chartPointsProp.length = 0;
            chartPointsProp.push(...props.points);
            return () => h('div', { 'data-testid': 'chart-stub' });
        },
    }),
}));

import MedicationDemand from './MedicationDemand.vue';

function medicationSummary(overrides: Partial<MedicationSummary> = {}): MedicationSummary {
    return {
        uuid: 'med-1',
        name: 'Paracetamol',
        presentation: 'Tableta',
        concentration: '500 mg',
        ...overrides,
    };
}

function okProjection(overrides: Partial<DemandProjection> = {}): DemandProjection {
    return {
        status: 'ok',
        history: [{ month: '2025-12', consumed: 100 }],
        fitted: [{ month: '2025-12', value: 95 }],
        projection: [{ month: '2026-01', value: 108.1 }],
        slope: 13.1,
        intercept: 10,
        r_squared: 0.8734,
        next_month_demand: 108,
        current_stock: 40,
        suggested_reorder_quantity: 68,
        medication: medicationSummary(),
        is_indicative: true,
        ...overrides,
    };
}

function insufficientProjection(overrides: Partial<DemandProjection> = {}): DemandProjection {
    return {
        status: 'insufficient_data',
        history: [{ month: '2026-02', consumed: 10 }],
        fitted: [],
        projection: [],
        slope: null,
        intercept: null,
        r_squared: null,
        next_month_demand: null,
        current_stock: 40,
        suggested_reorder_quantity: null,
        medication: medicationSummary(),
        is_indicative: true,
        ...overrides,
    };
}

let wrapper: VueWrapper | null = null;

function mountPage(medications: MedicationSummary[], projection: DemandProjection | null): VueWrapper {
    wrapper = mount(MedicationDemand, { props: { medications, projection } });
    return wrapper;
}

describe('Pages/reports/MedicationDemand.vue', () => {
    beforeEach(() => {
        routerGet.mockClear();
        chartPointsProp.length = 0;
    });

    afterEach(() => {
        wrapper?.unmount();
        wrapper = null;
    });

    it('titles the page Proyección de demanda', () => {
        const page = mountPage([medicationSummary()], null);

        expect(page.get('h1').text()).toBe('Proyección de demanda');
        expect(page.findComponent({ name: 'InertiaHead' }).props('title')).toBe('Proyección de demanda');
    });

    it('navigates with the selected medication uuid on selection, preserving scroll and state', () => {
        const page = mountPage([medicationSummary(), medicationSummary({ uuid: 'med-2', name: 'Ibuprofeno' })], null);

        page.findComponent(ComboboxField).vm.$emit('select', 'med-2');

        expect(routerGet).toHaveBeenCalledWith(
            medicationDemand().url,
            { medication: 'med-2' },
            { preserveScroll: true, preserveState: true },
        );
    });

    it('shows a prompt to choose a medication when no projection is selected', () => {
        const page = mountPage([medicationSummary()], null);

        expect(page.text()).toContain('Selecciona un medicamento para ver su proyección');
        expect(page.findComponent({ name: 'DemandProjectionChartStub' }).exists()).toBe(false);
        expect(page.text()).not.toContain('Demanda proyectada próximo mes');
    });

    it('shows the insufficient-data empty state instead of the chart or cards', () => {
        const page = mountPage([medicationSummary()], insufficientProjection());

        expect(page.text()).toContain('Datos insuficientes: se necesitan al menos 2 meses completos de consumo');
        expect(page.findComponent({ name: 'DemandProjectionChartStub' }).exists()).toBe(false);
        expect(page.text()).not.toContain('Demanda proyectada próximo mes');
        expect(page.text()).not.toContain('Reorden sugerido');
    });

    it('renders the four summary cards, the demo note, and the chart for an ok projection', () => {
        const page = mountPage([medicationSummary()], okProjection());
        const text = page.text();

        expect(text).toContain('Demanda proyectada próximo mes');
        expect(text).toContain('108');
        expect(text).toContain('Existencia actual');
        expect(text).toContain('40');
        expect(text).toContain('Reorden sugerido');
        expect(text).toContain('68');
        expect(text).toContain('Ajuste R²');
        expect(text).toContain('0.87');
        expect(text).toContain(
            'Demostración: proyección indicativa por regresión lineal simple sobre el consumo mensual; no sustituye el criterio de compra.',
        );
        expect(text).toContain('Tendencia: +13.1 unidades/mes');
        expect(page.findComponent({ name: 'DemandProjectionChartStub' }).exists()).toBe(true);
        expect(chartPointsProp.length).toBeGreaterThan(0);
    });
});
