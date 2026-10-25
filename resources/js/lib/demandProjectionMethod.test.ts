import { describe, expect, it } from 'vitest';
import {
    CHART_METHOD_LABELS,
    formatError,
    formatMonths,
    METHOD_LABELS,
    METHOD_REASON_TEXTS,
    PATTERN_LABELS,
    shouldWarnAboutProjection,
    summarizeBacktestError,
} from '@/lib/demandProjectionMethod';
import type { DemandPatternSummary, DemandProjection } from '@/types/inventory';

function pattern(cls: DemandPatternSummary['class']): DemandPatternSummary {
    return { class: cls, adi: 1, cv2: 0.1, seasonality: 'not_assessed' };
}

function projection(overrides: Partial<DemandProjection> = {}): DemandProjection {
    return {
        status: 'ok',
        history: [],
        fitted: [],
        projection: [],
        slope: null,
        intercept: null,
        r_squared: null,
        next_month_demand: 10,
        current_stock: 0,
        suggested_reorder_quantity: 10,
        method: 'historical_mean',
        method_reason: 'mean_beats_linear',
        backtest: {
            horizon: 1,
            margin: 0.05,
            evaluated_points: 4,
            linear_trend: { mae: 6, rmse: 7 },
            historical_mean: { mae: 3.25, rmse: 4 },
        },
        demand_pattern: pattern('smooth'),
        medication: { uuid: 'm', name: 'N', presentation: 'P', concentration: 'C' },
        is_indicative: true,
        ...overrides,
    };
}

describe('lib/demandProjectionMethod', () => {
    it('labels both methods in Spanish with accents', () => {
        expect(METHOD_LABELS.linear_trend).toBe('Regresión lineal');
        expect(METHOD_LABELS.historical_mean).toBe('Promedio histórico');
        expect(CHART_METHOD_LABELS.historical_mean).toBe('Promedio histórico (método elegido)');
        expect(CHART_METHOD_LABELS.linear_trend).toBe('Tendencia lineal (método elegido)');
    });

    it('has a reason text for every reason code', () => {
        for (const reason of ['short_history', 'linear_beats_mean', 'mean_beats_linear'] as const) {
            expect(METHOD_REASON_TEXTS[reason].length).toBeGreaterThan(0);
        }
        expect(METHOD_REASON_TEXTS.short_history).toContain('9 meses');
    });

    it.each([
        ['smooth', 'Regular'],
        ['erratic', 'Errático'],
        ['intermittent', 'Intermitente'],
        ['lumpy', 'Irregular'],
    ] as const)('labels the %s pattern as %s', (cls, label) => {
        expect(PATTERN_LABELS[cls]).toBe(label);
    });

    it.each([
        ['smooth', null, false],
        ['erratic', null, true],
        ['intermittent', null, true],
        ['lumpy', null, true],
        ['smooth', 'short_history', true],
        ['smooth', 'linear_beats_mean', false],
    ] as const)('warns for pattern %s and reason %s: %s', (cls, reason, expected) => {
        expect(shouldWarnAboutProjection(pattern(cls), reason)).toBe(expected);
    });

    it('warns on short history even without a pattern', () => {
        expect(shouldWarnAboutProjection(null, 'short_history')).toBe(true);
        expect(shouldWarnAboutProjection(null, null)).toBe(false);
    });

    it('summarizes the MAE of both compared methods regardless of the chosen one', () => {
        const expected = { linearMae: 6, meanMae: 3.25, evaluatedMonths: 4 };

        expect(summarizeBacktestError(projection({ method: 'linear_trend' }))).toEqual(expected);
        expect(summarizeBacktestError(projection())).toEqual(expected);
    });

    it('returns null when there is no usable backtest', () => {
        expect(summarizeBacktestError(projection({ backtest: null }))).toBeNull();
        expect(summarizeBacktestError(projection({ method: null }))).toBeNull();

        const base = projection().backtest!;
        expect(summarizeBacktestError(projection({ backtest: { ...base, evaluated_points: 0 } }))).toBeNull();
        expect(
            summarizeBacktestError(projection({ backtest: { ...base, historical_mean: { mae: null, rmse: null } } })),
        ).toBeNull();
    });

    it('formats months and errors', () => {
        expect(formatMonths(1)).toBe('1 mes');
        expect(formatMonths(3)).toBe('3 meses');
        expect(formatError(3.25)).toBe('3.25');
    });
});
