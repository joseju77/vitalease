/* eslint-disable vue/one-component-per-file -- minimal inline test doubles for Inertia's Head and the chart component. */
import { mount, VueWrapper } from '@vue/test-utils';
import { defineComponent, h } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { medicationDemand } from '@/routes/reports';
import type { DemandProjection, MedicationSummary } from '@/types/inventory';

import ComboboxField from '@/components/form/ComboboxField.vue';

const { routerGet, chartPointsProp, chartMethodProp } = vi.hoisted(() => ({
    routerGet: vi.fn(),
    chartPointsProp: [] as unknown[],
    chartMethodProp: { value: undefined as string | undefined },
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
        props: { points: { type: Array, default: () => [] }, method: { type: String, default: undefined } },
        setup: (props) => {
            chartMethodProp.value = props.method;
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
        method: 'linear_trend',
        method_reason: 'linear_beats_mean',
        backtest: {
            horizon: 1,
            margin: 0.05,
            evaluated_points: 3,
            linear_trend: { mae: 4, rmse: 5 },
            historical_mean: { mae: 12, rmse: 14 },
        },
        demand_pattern: { class: 'smooth', adi: 1, cv2: 0.02, seasonality: 'not_assessed' },
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
        method: null,
        method_reason: null,
        backtest: null,
        demand_pattern: null,
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

    it('renders the three summary cards, the notice, and the chart for an ok projection', () => {
        const page = mountPage([medicationSummary()], okProjection());
        const text = page.text();

        expect(text).toContain('Demanda proyectada próximo mes');
        expect(text).toContain('108');
        expect(text).toContain('Existencia actual');
        expect(text).toContain('40');
        expect(text).toContain('Reorden sugerido');
        expect(text).toContain('68');
        expect(text).not.toContain('R²');
        expect(text).not.toContain('0.87');
        expect(text).toContain(
            'Esta estimación se calcula con lo que se ha entregado de este medicamento en los últimos meses. Es solo una referencia para planear compras: ninguno de los métodos toma en cuenta temporadas ni situaciones imprevistas, así que revísala con tu criterio antes de hacer un pedido.',
        );
        expect(text).toContain('En promedio, el consumo sube 13 unidades cada mes');
        expect(page.findComponent({ name: 'DemandProjectionChartStub' }).exists()).toBe(true);
        expect(chartPointsProp.length).toBeGreaterThan(0);
    });

    it.each([
        [-1.2, 'En promedio, el consumo baja 1 unidad cada mes'],
        [0.3, 'En promedio, el consumo se ha mantenido estable mes con mes'],
    ])('describes a slope of %s in plain language', (slope, expected) => {
        const page = mountPage([medicationSummary()], okProjection({ slope }));

        expect(page.text()).toContain(expected);
    });

    it('shows the linear method, its reason, and MAE next to the mean with evaluated months', () => {
        const page = mountPage([medicationSummary()], okProjection());
        const text = page.text();

        expect(page.get('[data-testid="method-badge"]').text()).toBe('Regresión lineal');
        expect(text).toContain('la regresión lineal se equivocó claramente menos');
        expect(page.get('[data-testid="backtest-error"]').text()).toContain('3 meses de prueba');
        expect(page.get('[data-testid="backtest-error-linear"]').text()).toContain(
            'Regresión lineal: 4 unidades (método elegido)',
        );
        expect(page.get('[data-testid="backtest-error-mean"]').text()).toContain('Promedio histórico: 12 unidades');
        expect(page.get('[data-testid="backtest-error-mean"]').text()).not.toContain('método elegido');
        expect(chartMethodProp.value).toBe('linear_trend');
    });

    it('shows the historical mean without slope wording and passes the method to the chart', () => {
        const page = mountPage(
            [medicationSummary()],
            okProjection({
                method: 'historical_mean',
                method_reason: 'mean_beats_linear',
                slope: null,
                intercept: null,
                r_squared: null,
            }),
        );

        expect(page.get('[data-testid="method-badge"]').text()).toBe('Promedio histórico');
        expect(page.get('[data-testid="backtest-error-linear"]').text()).toContain('Regresión lineal: 4 unidades');
        expect(page.get('[data-testid="backtest-error-linear"]').text()).not.toContain('método elegido');
        expect(page.get('[data-testid="backtest-error-mean"]').text()).toContain(
            'Promedio histórico: 12 unidades (método elegido)',
        );
        expect(page.text()).not.toContain('En promedio, el consumo');
        expect(chartMethodProp.value).toBe('historical_mean');
    });

    it('handles a backtest without evaluated months gracefully', () => {
        const page = mountPage(
            [medicationSummary()],
            okProjection({
                method: 'historical_mean',
                method_reason: 'short_history',
                slope: null,
                backtest: {
                    horizon: 1,
                    margin: 0.05,
                    evaluated_points: 0,
                    linear_trend: { mae: null, rmse: null },
                    historical_mean: { mae: null, rmse: null },
                },
            }),
        );

        expect(page.find('[data-testid="backtest-error"]').exists()).toBe(false);
        expect(page.get('[data-testid="backtest-error-unavailable"]').text()).toContain('Aún no hay meses suficientes');
        expect(page.text()).toContain('menos de 9 meses');
    });

    it('shows the pattern class with its explanation and no warning for a smooth pattern', () => {
        const page = mountPage([medicationSummary()], okProjection());

        expect(page.get('[data-testid="pattern-badge"]').text()).toBe('Regular');
        expect(page.get('[data-testid="pattern-card"]').text()).toContain('varía poco');
        expect(page.find('[data-testid="pattern-warning"]').exists()).toBe(false);
    });

    it.each([
        ['lumpy', 'linear_beats_mean'],
        ['smooth', 'short_history'],
    ] as const)('warns informationally for %s / %s while keeping the reorder card', (cls, reason) => {
        const page = mountPage(
            [medicationSummary()],
            okProjection({
                method_reason: reason,
                demand_pattern: { class: cls, adi: 2, cv2: 1, seasonality: 'not_assessed' },
            }),
        );

        expect(page.find('[data-testid="pattern-warning"]').exists()).toBe(true);
        expect(page.text()).toContain('Reorden sugerido');
        expect(page.text()).toContain('68');
    });

    it('always shows the reference note and the suggestion wording', () => {
        const page = mountPage([medicationSummary()], okProjection());
        const text = page.text();

        expect(text).toContain('Es solo una referencia para planear compras');
        expect(text).toContain('Sugerencia para apoyar tu decisión; no es una orden de compra.');
    });
});
