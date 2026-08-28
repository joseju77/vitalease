<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import type { AcceptableValue } from 'reka-ui';
import { computed, ref, watch } from 'vue';
import ComboboxField from '@/components/form/ComboboxField.vue';
import DemandProjectionChart from '@/components/reports/DemandProjectionChart.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { buildDemandChartPoints } from '@/lib/demandProjectionChart';
import AppLayout from '@/layouts/AppLayout.vue';
import { medicationDemand } from '@/routes/reports';
import type { DemandProjection, MedicationSummary } from '@/types/inventory';

defineOptions({
    layout: (h: any, page: any) => h(AppLayout, { title: 'Proyección de demanda' }, () => page),
});

const props = defineProps<{
    medications: MedicationSummary[];
    projection: DemandProjection | null;
}>();

/**
 * The combobox's own selection state, kept separate from `props.projection`
 * so it can be pre-filled on load and re-synced on every server round trip
 * (including browser back/forward, which updates props without going
 * through `selectMedication` below).
 */
const selectedMedication = ref<string | undefined>(props.projection?.medication.uuid);

watch(
    () => props.projection,
    (projection) => {
        selectedMedication.value = projection?.medication.uuid;
    },
);

const medicationOptions = computed(() =>
    props.medications.map((medication) => ({
        value: medication.uuid,
        label: `${medication.name} · ${medication.presentation} ${medication.concentration}`.trim(),
    })),
);

/** Fixed 3-month horizon: the report has no horizon selector. */
function selectMedication(value: AcceptableValue | AcceptableValue[]) {
    if (typeof value !== 'string') {
        return;
    }

    router.get(medicationDemand().url, { medication: value }, { preserveScroll: true, preserveState: true });
}

const chartPoints = computed(() => (props.projection?.status === 'ok' ? buildDemandChartPoints(props.projection) : []));

/** e.g. "Tendencia: +13.1 unidades/mes", or `null` when there is no fitted slope yet. */
const slopeLabel = computed(() => {
    const slope = props.projection?.slope;

    if (slope === null || slope === undefined) {
        return null;
    }

    const sign = slope >= 0 ? '+' : '';
    return `Tendencia: ${sign}${slope.toFixed(1)} unidades/mes`;
});
</script>

<template>
    <Head title="Proyección de demanda" />

    <div class="space-y-6">
        <div class="space-y-1">
            <h1 class="text-2xl font-semibold">Proyección de demanda</h1>
            <p class="text-sm text-muted-foreground">
                Selecciona un medicamento para ver su proyección de demanda a 3 meses.
            </p>
        </div>

        <ComboboxField
            id="medication-demand-selector"
            :model-value="selectedMedication"
            label="Medicamento"
            placeholder="Selecciona un medicamento"
            search-placeholder="Buscar medicamento..."
            empty-message="Sin resultados."
            class="max-w-md"
            :options="medicationOptions"
            @select="selectMedication"
        />

        <Alert v-if="projection !== null">
            <AlertDescription>
                Demostración: proyección indicativa por regresión lineal simple sobre el consumo mensual; no sustituye
                el criterio de compra.
            </AlertDescription>
        </Alert>

        <Card v-if="projection === null">
            <CardContent class="py-10 text-center text-muted-foreground">
                Selecciona un medicamento para ver su proyección
            </CardContent>
        </Card>

        <Card v-else-if="projection.status === 'insufficient_data'">
            <CardContent class="py-10 text-center text-muted-foreground">
                Datos insuficientes: se necesitan al menos 2 meses completos de consumo
            </CardContent>
        </Card>

        <template v-else>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Card>
                    <CardHeader>
                        <CardTitle class="text-sm font-medium text-muted-foreground">
                            Demanda proyectada próximo mes
                        </CardTitle>
                    </CardHeader>
                    <CardContent class="text-2xl font-semibold">{{ projection.next_month_demand }}</CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle class="text-sm font-medium text-muted-foreground">Existencia actual</CardTitle>
                    </CardHeader>
                    <CardContent class="text-2xl font-semibold">{{ projection.current_stock }}</CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle class="text-sm font-medium text-muted-foreground">Reorden sugerido</CardTitle>
                        <CardDescription>Cantidad indicativa, no una orden de compra.</CardDescription>
                    </CardHeader>
                    <CardContent class="text-2xl font-semibold">
                        {{ projection.suggested_reorder_quantity }}
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle class="text-sm font-medium text-muted-foreground">Ajuste R²</CardTitle>
                    </CardHeader>
                    <CardContent class="text-2xl font-semibold">
                        {{ projection.r_squared !== null ? projection.r_squared.toFixed(2) : '—' }}
                    </CardContent>
                </Card>
            </div>

            <p v-if="slopeLabel" class="text-sm text-muted-foreground">{{ slopeLabel }}</p>

            <DemandProjectionChart :points="chartPoints" />
        </template>
    </div>
</template>
