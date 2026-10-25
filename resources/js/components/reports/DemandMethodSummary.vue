<script setup lang="ts">
import { computed } from 'vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    formatError,
    formatMonths,
    METHOD_LABELS,
    METHOD_REASON_TEXTS,
    PATTERN_EXPLANATIONS,
    PATTERN_LABELS,
    SEASONALITY_TEXTS,
    shouldWarnAboutProjection,
    summarizeBacktestError,
} from '@/lib/demandProjectionMethod';
import type { DemandProjection } from '@/types/inventory';

/**
 * Presentational summary of how a projection was produced: the chosen method
 * and why, its backtest error next to the historical mean's, the demand
 * pattern, and an informational warning. The warning never hides or changes
 * the reorder suggestion, which the page renders independently.
 */
const props = defineProps<{
    projection: DemandProjection;
}>();

const methodLabel = computed(() => (props.projection.method ? METHOD_LABELS[props.projection.method] : null));
const reasonText = computed(() =>
    props.projection.method_reason ? METHOD_REASON_TEXTS[props.projection.method_reason] : null,
);
const errorSummary = computed(() => summarizeBacktestError(props.projection));
const pattern = computed(() => props.projection.demand_pattern);
const showWarning = computed(() => shouldWarnAboutProjection(pattern.value, props.projection.method_reason));
</script>

<template>
    <div class="space-y-4">
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <Card v-if="methodLabel" data-testid="method-card">
                <CardHeader>
                    <CardTitle class="text-sm font-medium text-muted-foreground">Método de proyección</CardTitle>
                </CardHeader>
                <CardContent class="space-y-3 text-sm">
                    <Badge data-testid="method-badge">{{ methodLabel }}</Badge>
                    <p v-if="reasonText">{{ reasonText }}</p>
                    <div v-if="errorSummary" class="space-y-1" data-testid="backtest-error">
                        <p>
                            Error promedio por mes (MAE), medido en
                            {{ formatMonths(errorSummary.evaluatedMonths) }} de prueba:
                        </p>
                        <ul class="list-inside list-disc">
                            <li data-testid="backtest-error-linear">
                                {{ METHOD_LABELS.linear_trend }}:
                                {{ formatError(errorSummary.linearMae) }} unidades<span
                                    v-if="projection.method === 'linear_trend'"
                                >
                                    (método elegido)</span
                                >
                            </li>
                            <li data-testid="backtest-error-mean">
                                {{ METHOD_LABELS.historical_mean }}:
                                {{ formatError(errorSummary.meanMae) }} unidades<span
                                    v-if="projection.method === 'historical_mean'"
                                >
                                    (método elegido)</span
                                >
                            </li>
                        </ul>
                    </div>
                    <p v-else class="text-muted-foreground" data-testid="backtest-error-unavailable">
                        Aún no hay meses suficientes para medir el error de la proyección.
                    </p>
                </CardContent>
            </Card>

            <Card v-if="pattern" data-testid="pattern-card">
                <CardHeader>
                    <CardTitle class="text-sm font-medium text-muted-foreground">Patrón de consumo</CardTitle>
                </CardHeader>
                <CardContent class="space-y-3 text-sm">
                    <Badge variant="secondary" data-testid="pattern-badge">{{ PATTERN_LABELS[pattern.class] }}</Badge>
                    <p>{{ PATTERN_EXPLANATIONS[pattern.class] }}</p>
                    <p class="text-muted-foreground">{{ SEASONALITY_TEXTS[pattern.seasonality] }}</p>
                </CardContent>
            </Card>
        </div>

        <Alert v-if="showWarning" data-testid="pattern-warning">
            <AlertTitle>Revisa esta sugerencia con especial cuidado</AlertTitle>
            <AlertDescription>
                El consumo de este medicamento es poco regular o el historial es corto, por lo que la proyección es
                menos confiable. La sugerencia de reorden se muestra de todos modos; úsala solo como apoyo y valídala
                con tu criterio.
            </AlertDescription>
        </Alert>
    </div>
</template>
