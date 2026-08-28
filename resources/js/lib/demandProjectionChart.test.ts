import { describe, expect, it } from 'vitest';
import type { DemandProjection } from '@/types/inventory';
import { buildDemandChartPoints, formatMonthLabel } from './demandProjectionChart';

function projection(overrides: Partial<DemandProjection> = {}): DemandProjection {
    return {
        status: 'ok',
        history: [
            { month: '2025-11', consumed: 100 },
            { month: '2025-12', consumed: 120 },
            { month: '2026-01', consumed: 140 },
        ],
        fitted: [
            { month: '2025-11', value: 98 },
            { month: '2025-12', value: 119 },
            { month: '2026-01', value: 140 },
        ],
        projection: [
            { month: '2026-02', value: 161 },
            { month: '2026-03', value: 182 },
            { month: '2026-04', value: 203 },
        ],
        slope: 21,
        intercept: 77,
        r_squared: 0.98,
        next_month_demand: 161,
        current_stock: 300,
        suggested_reorder_quantity: 0,
        medication: { uuid: 'med-1', name: 'Paracetamol', presentation: 'Tableta', concentration: '500 mg' },
        is_indicative: true,
        ...overrides,
    };
}

/**
 * The expected label is computed with the same `Intl.DateTimeFormat`
 * settings `formatMonthLabel` uses (es-MX, short month, UTC), rather than
 * hardcoded locale strings, following `ConsultationList.test.ts`'s
 * convention: CI's ICU data may not carry the `es-MX` locale, in which case
 * both sides fall back to the same (e.g. English) label consistently.
 */
function expectedMonthLabel(year: number, monthNumber: number): string {
    return new Intl.DateTimeFormat('es-MX', { month: 'short', year: 'numeric', timeZone: 'UTC' }).format(
        new Date(Date.UTC(year, monthNumber - 1, 1)),
    );
}

describe('formatMonthLabel', () => {
    it('formats a Y-m month key as a short month and year', () => {
        expect(formatMonthLabel('2026-03')).toBe(expectedMonthLabel(2026, 3));
    });

    it('parses the month as UTC so the local time zone never shifts it across a year boundary', () => {
        expect(formatMonthLabel('2026-01')).toBe(expectedMonthLabel(2026, 1));
        expect(formatMonthLabel('2025-12')).toBe(expectedMonthLabel(2025, 12));
        // A UTC-unsafe implementation (e.g. `new Date(year, month - 1, 1)` in a
        // negative-offset time zone) could collapse these two into the same
        // label; assert they stay distinct.
        expect(formatMonthLabel('2026-01')).not.toBe(formatMonthLabel('2025-12'));
    });
});

describe('buildDemandChartPoints', () => {
    it('merges history, fitted, and projection into one chronologically ordered list', () => {
        const points = buildDemandChartPoints(projection());

        expect(points.map((point) => point.month)).toEqual([
            '2025-11',
            '2025-12',
            '2026-01',
            '2026-02',
            '2026-03',
            '2026-04',
        ]);
        expect(points.map((point) => point.index)).toEqual([0, 1, 2, 3, 4, 5]);
    });

    it('sets each point label from formatMonthLabel', () => {
        const points = buildDemandChartPoints(projection());

        for (const point of points) {
            expect(point.label).toBe(formatMonthLabel(point.month));
        }
    });

    it('leaves fitted and projection undefined for months with no data (gaps)', () => {
        const points = buildDemandChartPoints(projection());
        const nov = points.find((point) => point.month === '2025-11')!;
        const feb = points.find((point) => point.month === '2026-02')!;

        expect(nov.history).toBe(100);
        expect(nov.projection).toBeUndefined();
        expect(feb.history).toBeUndefined();
        expect(feb.fitted).toBeUndefined();
        expect(feb.projection).toBe(161);
    });

    it('anchors the projection line at the last fitted point so it continues the fitted line', () => {
        const points = buildDemandChartPoints(projection());
        const jan = points.find((point) => point.month === '2026-01')!;

        expect(jan.history).toBe(140);
        expect(jan.fitted).toBe(140);
        expect(jan.projection).toBe(140);
    });

    it('does not fabricate a projection point when the series has insufficient data', () => {
        const points = buildDemandChartPoints(
            projection({
                status: 'insufficient_data',
                history: [{ month: '2026-01', consumed: 50 }],
                fitted: [],
                projection: [],
            }),
        );

        expect(points).toEqual([{ index: 0, month: '2026-01', label: formatMonthLabel('2026-01'), history: 50 }]);
    });
});
