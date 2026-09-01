/**
 * Pure helpers for the medication demand projection chart
 * (`components/reports/DemandProjectionChart.vue`). Kept isolated from
 * Unovis/shadcn-vue chart rendering so the merge logic is trivially testable.
 */
import type { DemandChartPoint, DemandProjection } from '@/types/inventory';

const monthLabelFormatter = new Intl.DateTimeFormat('es-MX', {
    month: 'short',
    year: 'numeric',
    timeZone: 'UTC',
});

/**
 * Formats a `Y-m` month key (as serialized by
 * `App\Services\Inventory\DemandProjectionResult`) into a short, localized
 * month/year label, e.g. `'2026-03'` -> `'mar 2026'`. Parsed as a UTC
 * calendar month so the browser's local time zone never shifts it.
 */
export function formatMonthLabel(month: string): string {
    const [year, monthNumber] = month.split('-').map(Number);

    return monthLabelFormatter.format(new Date(Date.UTC(year, monthNumber - 1, 1)));
}

/**
 * Merges a medication's `history` (consumed), `fitted` (OLS trend), and
 * `projection` (future estimate) series into one chronologically ordered
 * list of chart points, indexed for the chart's shared numeric X axis.
 *
 * A series value is left `undefined` for any month it has no entry for, so
 * `VisLine` renders a gap there instead of interpolating across it. The last
 * `fitted` point is also copied onto that same point's `projection` field,
 * anchoring the dashed projection line so it visually continues from where
 * the solid fitted line ends.
 */
export function buildDemandChartPoints(projection: DemandProjection): DemandChartPoint[] {
    const order: string[] = [];
    const pointsByMonth = new Map<string, Omit<DemandChartPoint, 'index'>>();

    function pointFor(month: string): Omit<DemandChartPoint, 'index'> {
        let point = pointsByMonth.get(month);

        if (!point) {
            point = { month, label: formatMonthLabel(month) };
            pointsByMonth.set(month, point);
            order.push(month);
        }

        return point;
    }

    for (const { month, consumed } of projection.history) {
        pointFor(month).history = consumed;
    }

    for (const { month, value } of projection.fitted) {
        pointFor(month).fitted = value;
    }

    for (const { month, value } of projection.projection) {
        pointFor(month).projection = value;
    }

    const lastFitted = projection.fitted.at(-1);
    if (lastFitted) {
        pointFor(lastFitted.month).projection = lastFitted.value;
    }

    return order.map((month, index) => ({ ...pointsByMonth.get(month)!, index }));
}
