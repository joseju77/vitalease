<script setup lang="ts">
import { computed } from 'vue';
import { VisAxis, VisCrosshair, VisLine, VisTooltip, VisXYContainer } from '@unovis/vue';
import { ChartContainer, ChartLegendContent, ChartTooltipContent, componentToString } from '@/components/ui/chart';
import type { ChartConfig } from '@/components/ui/chart';
import { CHART_METHOD_LABELS } from '@/lib/demandProjectionMethod';
import type { DemandChartPoint, DemandProjectionMethod } from '@/types/inventory';

/**
 * Three-series demand chart for one medication: historical consumption and
 * the chosen method's fitted line (a trend for linear regression, a flat line
 * for the historical mean) render as solid lines, the future projection as a
 * dashed line that continues from the fitted line's last point. Only the
 * chosen method is drawn, and its legend entry says so. Points are
 * pre-merged by `lib/demandProjectionChart.ts`'s `buildDemandChartPoints`,
 * so this component only maps fields to Unovis accessors.
 */
const props = withDefaults(
    defineProps<{
        points: DemandChartPoint[];
        method?: DemandProjectionMethod;
    }>(),
    { method: 'linear_trend' },
);

const chartConfig = computed<ChartConfig>(() => ({
    history: { label: 'Consumo real', color: 'var(--chart-1)' },
    fitted: { label: CHART_METHOD_LABELS[props.method], color: 'var(--chart-2)' },
    projection: { label: 'Estimación', color: 'var(--chart-3)' },
}));

/** SVG `stroke-dasharray`, applied only to the projection line. */
const PROJECTION_DASH_ARRAY = [6, 4];

function xAccessor(point: DemandChartPoint): number {
    return point.index;
}

function historyAccessor(point: DemandChartPoint): number | undefined {
    return point.history;
}

function fittedAccessor(point: DemandChartPoint): number | undefined {
    return point.fitted;
}

function projectionAccessor(point: DemandChartPoint): number | undefined {
    return point.projection;
}

/** X axis tick formatter: the tick value is a point's numeric `index`. */
function labelForIndex(index: number): string {
    return props.points[index]?.label ?? '';
}

const tooltipTemplate = computed(() =>
    componentToString(chartConfig.value, ChartTooltipContent, {
        labelFormatter: labelForIndex,
    }),
);
</script>

<template>
    <ChartContainer :config="chartConfig" cursor class="aspect-auto h-[320px] w-full">
        <VisXYContainer :data="points">
            <VisLine :data="points" :x="xAccessor" :y="historyAccessor" color="var(--color-history)" />
            <VisLine :data="points" :x="xAccessor" :y="fittedAccessor" color="var(--color-fitted)" />
            <VisLine
                :data="points"
                :x="xAccessor"
                :y="projectionAccessor"
                color="var(--color-projection)"
                :line-dash-array="PROJECTION_DASH_ARRAY"
            />
            <VisAxis type="x" position="bottom" :tick-values="points.map(xAccessor)" :tick-format="labelForIndex" />
            <VisAxis type="y" position="left" />
            <VisCrosshair
                :data="points"
                :x="xAccessor"
                :y="[historyAccessor, fittedAccessor, projectionAccessor]"
                :template="tooltipTemplate"
            />
            <VisTooltip />
        </VisXYContainer>
        <ChartLegendContent />
    </ChartContainer>
</template>
