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

/**
 * Plain-language trend sentence for clinical staff, e.g. "En promedio, el
 * consumo sube 13 unidades cada mes", or `null` when there is no fitted slope.
 */
const slopeLabel = computed(() => {
    const slope = props.projection?.slope;

    if (slope === null || slope === undefined) {
        return null;
    }

    const units = Math.round(Math.abs(slope));

    if (units === 0) {
        return 'En promedio, el consumo se ha mantenido estable mes con mes';
    }

    const direction = slope > 0 ? 'sube' : 'baja';
    return `En promedio, el consumo ${direction} ${units} ${units === 1 ? 'unidad' : 'unidades'} cada mes`;
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
                Esta estimación se calcula con lo que se ha entregado de este medicamento en los últimos meses. Es solo
                una referencia para planear compras: no toma en cuenta temporadas ni situaciones imprevistas, así que
                revísala con tu criterio antes de hacer un pedido.
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
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
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
            </div>

            <p v-if="slopeLabel" class="text-sm text-muted-foreground">{{ slopeLabel }}</p>

            <DemandProjectionChart :points="chartPoints" />
        </template>
    </div>
</template>
