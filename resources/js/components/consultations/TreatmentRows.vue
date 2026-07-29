<script setup lang="ts">
import { Plus, Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import TextField from '@/components/form/TextField.vue';
import { Button } from '@/components/ui/button';
import { Card, CardAction, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import type { TreatmentRow } from '@/types/consultations';

const MAX_ROWS = 20;
const rows = defineModel<TreatmentRow[]>({ required: true });
defineProps<{ errors: Record<string, string> }>();
const fields = [
    ['medication', 'Medicamento'],
    ['dose', 'Dosis'],
    ['frequency', 'Frecuencia'],
    ['duration', 'Duración'],
] as const;
const isAtLimit = computed(() => rows.value.length >= MAX_ROWS);
function addRow() {
    if (rows.value.length < MAX_ROWS) rows.value.push({ medication: '', dose: '', frequency: '', duration: '' });
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
                class="grid grid-cols-2 items-start gap-3 rounded-lg border bg-muted/30 p-3 md:grid-cols-[minmax(0,2fr)_repeat(3,minmax(0,1fr))_auto] dark:bg-muted/10"
            >
                <TextField
                    v-for="[field, label] in fields"
                    :id="`treatment-${index}-${field}`"
                    :key="field"
                    v-model="row[field]"
                    :label="label"
                    :class="field === 'medication' ? 'col-span-2 md:col-span-1' : undefined"
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
