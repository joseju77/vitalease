/**
 * Spanish copy and display rules for the medication demand projection's
 * method selection, backtest error and demand pattern. Kept pure so the
 * report page and the chart share one source of wording.
 */
import type {
    DemandPatternSummary,
    DemandProjection,
    DemandProjectionBacktest,
    DemandProjectionMethod,
    DemandProjectionMethodReason,
} from '@/types/inventory';

/** Minimum months of history before the backtest can pick a method (mirrors the backend rule). */
export const MINIMUM_MONTHS_FOR_METHOD_SELECTION = 9;

export const METHOD_LABELS: Record<DemandProjectionMethod, string> = {
    linear_trend: 'Regresión lineal',
    historical_mean: 'Promedio histórico',
};

/** Label for the chart line of the chosen method. */
export const CHART_METHOD_LABELS: Record<DemandProjectionMethod, string> = {
    linear_trend: 'Tendencia lineal (método elegido)',
    historical_mean: 'Promedio histórico (método elegido)',
};

export const METHOD_REASON_TEXTS: Record<DemandProjectionMethodReason, string> = {
    short_history: `Hay menos de ${MINIMUM_MONTHS_FOR_METHOD_SELECTION} meses de historial, así que no se pudo comparar con pruebas pasadas y se usa el promedio histórico, que es el método más simple.`,
    linear_beats_mean:
        'En pruebas con los meses anteriores, la regresión lineal se equivocó claramente menos que el promedio histórico.',
    mean_beats_linear:
        'En pruebas con los meses anteriores, la regresión lineal no mejoró de forma clara al promedio histórico, así que se usa el promedio.',
};

export const PATTERN_LABELS: Record<DemandPatternSummary['class'], string> = {
    smooth: 'Regular',
    erratic: 'Errático',
    intermittent: 'Intermitente',
    lumpy: 'Irregular',
    no_demand: 'Sin demanda',
};

export const PATTERN_EXPLANATIONS: Record<DemandPatternSummary['class'], string> = {
    smooth: 'El consumo ocurre casi todos los meses y varía poco.',
    erratic: 'El consumo ocurre casi todos los meses, pero las cantidades cambian mucho de un mes a otro.',
    intermittent: 'Hay meses sin consumo, aunque cuando ocurre las cantidades son parecidas.',
    lumpy: 'Hay meses sin consumo y, cuando ocurre, las cantidades cambian mucho.',
    no_demand: 'No se registró consumo suficiente para describir un patrón.',
};

export const SEASONALITY_TEXTS: Record<DemandPatternSummary['seasonality'], string> = {
    seasonal: 'Se detecta un patrón que se repite cada año; la proyección no lo incorpora.',
    not_seasonal: 'No se detecta un patrón que se repita cada año.',
    not_assessed: 'Se necesitan al menos 24 meses para revisar si hay patrón anual.',
};

const WARNING_PATTERNS: ReadonlyArray<DemandPatternSummary['class']> = ['erratic', 'intermittent', 'lumpy'];

/**
 * Whether the informational warning applies: an erratic, intermittent or
 * lumpy pattern, or a history too short for method selection. It never hides
 * or alters the reorder suggestion.
 */
export function shouldWarnAboutProjection(
    pattern: DemandPatternSummary | null,
    reason: DemandProjectionMethodReason | null,
): boolean {
    return reason === 'short_history' || (pattern !== null && WARNING_PATTERNS.includes(pattern.class));
}

/** Spanish month count, e.g. "1 mes", "3 meses". */
export function formatMonths(count: number): string {
    return `${count} ${count === 1 ? 'mes' : 'meses'}`;
}

const numberFormatter = new Intl.NumberFormat('es-MX', { maximumFractionDigits: 2 });

export interface BacktestErrorSummary {
    linearMae: number;
    meanMae: number;
    evaluatedMonths: number;
}

/**
 * MAE of both compared methods (linear trend and historical mean), or `null`
 * when the backtest has no evaluated months or no usable error (null/0 points).
 */
export function summarizeBacktestError(projection: DemandProjection): BacktestErrorSummary | null {
    const backtest: DemandProjectionBacktest | null = projection.backtest;
    const method = projection.method;

    if (backtest === null || method === null || backtest.evaluated_points <= 0) {
        return null;
    }

    const linearMae = backtest.linear_trend.mae;
    const meanMae = backtest.historical_mean.mae;

    if (linearMae === null || meanMae === null) {
        return null;
    }

    return { linearMae, meanMae, evaluatedMonths: backtest.evaluated_points };
}

export function formatError(value: number): string {
    return numberFormatter.format(value);
}
