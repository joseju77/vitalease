<script setup lang="ts">
import TextField from '@/components/form/TextField.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import type { VitalSignsForm } from '@/types/consultations';
const signs = defineModel<VitalSignsForm>({ required: true });
defineProps<{ errors: Record<string, string> }>();

type VitalField = {
    field: keyof VitalSignsForm;
    label: string;
    unit: string;
    step: string;
    inputmode: 'decimal' | 'numeric';
};

const decimal = (field: keyof VitalSignsForm, label: string, unit: string, step: string): VitalField => ({
    field,
    label,
    unit,
    step,
    inputmode: 'decimal',
});
const integer = (field: keyof VitalSignsForm, label: string, unit: string): VitalField => ({
    field,
    label,
    unit,
    step: '1',
    inputmode: 'numeric',
});

const leadingFields = [decimal('weight', 'Peso', 'kg', '0.01'), decimal('height', 'Talla', 'm', '0.01')];
const bloodPressureFields = [
    integer('blood_pressure_systolic', 'TA sistólica', 'mmHg'),
    integer('blood_pressure_diastolic', 'TA diastólica', 'mmHg'),
];
const trailingFields = [
    integer('heart_rate', 'FC', 'lpm'),
    integer('respiratory_rate', 'FR', 'rpm'),
    decimal('temperature', 'Temperatura', '°C', '0.1'),
    integer('oxygen_saturation', 'SpO2', '%'),
    integer('glasgow', 'Glasgow', '3–15'),
    integer('glucose', 'Glucosa', 'mg/dL · opcional'),
];
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle>Signos vitales</CardTitle>
            <CardDescription>Registra las mediciones tomadas durante la consulta.</CardDescription>
        </CardHeader>
        <CardContent>
            <div class="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-5">
                <TextField
                    v-for="item in leadingFields"
                    :id="`vital-${item.field}`"
                    :key="item.field"
                    v-model="signs[item.field]"
                    :label="item.label"
                    :sub-label="item.unit"
                    :errors="errors[`vital_signs.${item.field}`]"
                    type="number"
                    :step="item.step"
                    :inputmode="item.inputmode"
                    required
                />
                <div
                    role="group"
                    aria-label="Tensión arterial"
                    class="col-span-2 grid grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] items-start gap-2"
                >
                    <template v-for="(item, index) in bloodPressureFields" :key="item.field">
                        <span v-if="index > 0" aria-hidden="true" class="mt-7 text-lg leading-8 text-muted-foreground"
                            >/</span
                        >
                        <TextField
                            :id="`vital-${item.field}`"
                            v-model="signs[item.field]"
                            :label="item.label"
                            :sub-label="item.unit"
                            :errors="errors[`vital_signs.${item.field}`]"
                            type="number"
                            :step="item.step"
                            :inputmode="item.inputmode"
                            required
                        />
                    </template>
                </div>
                <TextField
                    v-for="item in trailingFields"
                    :id="`vital-${item.field}`"
                    :key="item.field"
                    v-model="signs[item.field]"
                    :label="item.label"
                    :sub-label="item.unit"
                    :errors="errors[`vital_signs.${item.field}`]"
                    type="number"
                    :step="item.step"
                    :inputmode="item.inputmode"
                    :required="item.field !== 'glucose'"
                />
            </div>
        </CardContent>
    </Card>
</template>
