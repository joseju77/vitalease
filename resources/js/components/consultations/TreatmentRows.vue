<script setup lang="ts">
import { Plus, Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import ComboboxField from '@/components/form/ComboboxField.vue';
import TextField from '@/components/form/TextField.vue';
import { Button } from '@/components/ui/button';
import { Card, CardAction, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { medicationOptionLabel } from '@/lib/inventoryLabels';
import type { MedicationOption, TreatmentRowPayload } from '@/types/consultations';

const MAX_ROWS = 20;
const rows = defineModel<TreatmentRowPayload[]>({ required: true });
const props = defineProps<{ errors: Record<string, string>; medicationOptions: MedicationOption[] }>();

const medicationComboboxOptions = computed(() =>
    props.medicationOptions.map((option) => ({ value: option.uuid, label: medicationOptionLabel(option) })),
);

const fields = [
    ['dose', 'Dosis'],
    ['frequency', 'Frecuencia'],
    ['duration', 'Duración'],
] as const;
const isAtLimit = computed(() => rows.value.length >= MAX_ROWS);
function addRow() {
    if (rows.value.length < MAX_ROWS) {
        rows.value.push({ medication_uuid: '', quantity_dispensed: 1, dose: '', frequency: '', duration: '' });
    }
}
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle>Tratamiento</CardTitle>
            <CardDescription>{{ rows.length }} de {{ MAX_ROWS }} medicamentos.</CardDescription>
            <CardAction>
                <Button type="button" variant="outline" size="sm" :disabled="isAtLimit" @click="addRow">
                    <Plus aria-hidden="true" />
                    Agregar medicamento
                </Button>
            </CardAction>
        </CardHeader>
        <CardContent class="space-y-3">
            <p v-if="errors.treatment" class="text-sm text-destructive">{{ errors.treatment }}</p>
            <p
                v-if="rows.length === 0"
                class="rounded-lg border border-dashed px-4 py-6 text-center text-sm text-muted-foreground"
            >
                No hay medicamentos agregados.
            </p>
            <div
                v-for="(row, index) in rows"
                :key="index"
                role="group"
                :aria-label="`Medicamento ${index + 1}`"
                class="grid grid-cols-2 items-start gap-3 rounded-lg border bg-muted/30 p-3 md:grid-cols-[minmax(0,2fr)_repeat(4,minmax(0,1fr))_auto] dark:bg-muted/10"
            >
                <ComboboxField
                    :id="`treatment-${index}-medication_uuid`"
                    v-model="row.medication_uuid"
                    label="Medicamento"
                    placeholder="Selecciona un medicamento"
                    search-placeholder="Buscar medicamento..."
                    empty-message="Sin resultados."
                    class="col-span-2 md:col-span-1"
                    :options="medicationComboboxOptions"
                    :errors="errors[`treatment.${index}.medication_uuid`]"
                    required
                />
                <TextField
                    :id="`treatment-${index}-quantity_dispensed`"
                    v-model="row.quantity_dispensed"
                    label="Cantidad"
                    type="number"
                    inputmode="numeric"
                    min="1"
                    :errors="errors[`treatment.${index}.quantity_dispensed`]"
                    required
                />
                <TextField
                    v-for="[field, label] in fields"
                    :id="`treatment-${index}-${field}`"
                    :key="field"
                    v-model="row[field]"
                    :label="label"
                    :errors="errors[`treatment.${index}.${field}`]"
                    required
                />
                <div class="mt-7 flex justify-end">
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        class="text-muted-foreground hover:text-destructive"
                        :aria-label="`Quitar medicamento ${index + 1}`"
                        @click="rows.splice(index, 1)"
                    >
                        <Trash2 aria-hidden="true" />
                    </Button>
                </div>
            </div>
            <p v-if="isAtLimit" class="text-sm text-muted-foreground">
                Alcanzaste el máximo de {{ MAX_ROWS }} medicamentos.
            </p>
        </CardContent>
    </Card>
</template>
