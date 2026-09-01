/* eslint-disable vue/one-component-per-file -- this file defines several minimal inline test-double
   components for `@unovis/vue`; splitting each into its own file would scatter throwaway test
   fixtures across the tree for no readability gain. */
import { mount } from '@vue/test-utils';
import { defineComponent, h } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { DemandChartPoint } from '@/types/inventory';

/**
 * jsdom cannot render Unovis's SVG output, so every `@unovis/vue` component
 * is stubbed to a plain `<div>` that records the props it received. Tests
 * assert on those recorded props (accessors, colors, dash arrays, tick
 * config) instead of rendered SVG.
 */
const { visLineProps, visAxisProps } = vi.hoisted(() => ({
    visLineProps: [] as Record<string, unknown>[],
    visAxisProps: [] as Record<string, unknown>[],
}));

vi.mock('@unovis/vue', () => ({
    VisXYContainer: defineComponent({
        props: { data: { type: Array, default: () => [] } },
        setup:
            (_props, { slots }) =>
            () =>
                h('div', slots.default?.()),
    }),
    VisLine: defineComponent({
        props: {
            data: { type: Array, default: () => [] },
            x: { type: Function, default: undefined },
            y: { type: Function, default: undefined },
            color: { type: String, default: undefined },
            lineDashArray: { type: Array, default: undefined },
        },
        setup: (props) => {
            visLineProps.push({ ...props });
            return () => h('div');
        },
    }),
    VisAxis: defineComponent({
        props: {
            type: { type: String, default: undefined },
            position: { type: String, default: undefined },
            tickValues: { type: Array, default: undefined },
            tickFormat: { type: Function, default: undefined },
        },
        setup: (props) => {
            visAxisProps.push({ ...props });
            return () => h('div');
        },
    }),
    VisCrosshair: defineComponent({
        props: {
            data: { type: Array, default: () => [] },
            x: { type: Function, default: undefined },
            y: { type: Array, default: undefined },
            template: { type: Function, default: undefined },
        },
        setup: () => () => h('div'),
    }),
    VisTooltip: defineComponent({
        setup: () => () => h('div'),
    }),
}));

import DemandProjectionChart from './DemandProjectionChart.vue';

function point(overrides: Partial<DemandChartPoint> = {}): DemandChartPoint {
    return { index: 0, month: '2026-01', label: 'ene 2026', ...overrides };
}

const points: DemandChartPoint[] = [
    point({ index: 0, month: '2025-12', label: 'dic 2025', history: 100 }),
    point({ index: 1, month: '2026-01', label: 'ene 2026', history: 120, fitted: 118, projection: 118 }),
    point({ index: 2, month: '2026-02', label: 'feb 2026', projection: 140 }),
];

beforeEach(() => {
    visLineProps.length = 0;
    visAxisProps.length = 0;
});

describe('DemandProjectionChart', () => {
    it('renders exactly three series: history, fitted, and projection', () => {
        mount(DemandProjectionChart, { props: { points } });

        expect(visLineProps).toHaveLength(3);
        expect(visLineProps.map((lineProps) => lineProps.color)).toEqual([
            'var(--color-history)',
            'var(--color-fitted)',
            'var(--color-projection)',
        ]);
    });

    it('dashes only the projection line', () => {
        mount(DemandProjectionChart, { props: { points } });

        expect(visLineProps[0].lineDashArray).toBeUndefined();
        expect(visLineProps[1].lineDashArray).toBeUndefined();
        expect(visLineProps[2].lineDashArray).toEqual([6, 4]);
    });

    it('maps x to the point index and y to each series field', () => {
        mount(DemandProjectionChart, { props: { points } });
        const sample = points[1];
        const [history, fitted, projection] = visLineProps as {
            x: (p: DemandChartPoint) => number;
            y: (p: DemandChartPoint) => number | undefined;
        }[];

        expect(history.x(sample)).toBe(sample.index);
        expect(history.y(sample)).toBe(sample.history);
        expect(fitted.y(sample)).toBe(sample.fitted);
        expect(projection.y(sample)).toBe(sample.projection);
    });

    it('renders an x axis with one tick per point index, formatted to its label, and a plain y axis', () => {
        mount(DemandProjectionChart, { props: { points } });

        expect(visAxisProps).toHaveLength(2);
        const [xAxis, yAxis] = visAxisProps as {
            type: string;
            position: string;
            tickValues?: number[];
            tickFormat?: (index: number) => string;
        }[];

        expect(xAxis.type).toBe('x');
        expect(xAxis.position).toBe('bottom');
        expect(xAxis.tickValues).toEqual([0, 1, 2]);
        expect(xAxis.tickFormat?.(1)).toBe('ene 2026');
        expect(xAxis.tickFormat?.(2)).toBe('feb 2026');

        expect(yAxis.type).toBe('y');
        expect(yAxis.position).toBe('left');
    });
});
