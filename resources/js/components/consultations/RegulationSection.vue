<script setup lang="ts">
import TextField from '@/components/form/TextField.vue';
import { Card, CardAction, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Label } from '@/components/ui/label';
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select';
import { Switch } from '@/components/ui/switch';
import { transferTypeLabel } from '@/lib/consultationLabels';
import type { RegulationForm } from '@/types/consultations';
const enabled = defineModel<boolean>('enabled', { required: true });
const regulation = defineModel<RegulationForm>('regulation', { required: true });
defineProps<{ errors: Record<string, string>; transferTypeOptions: number[] }>();
const optionalFields = [
    ['ambulance_registration', 'Registro de ambulancia'],
    ['regulation_number', 'Número de regulación'],
    ['clinic_id', 'Clínica'],
    ['receiver_physician', 'Médico receptor'],
] as const;
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle>Regulación médica</CardTitle>
            <CardDescription>Actívala solo si el paciente requiere traslado.</CardDescription>
            <CardAction class="flex items-center gap-2">
                <Label for="regulation-enabled" class="text-sm font-normal text-muted-foreground"
                    >Requiere traslado</Label
                >
                <Switch id="regulation-enabled" v-model="enabled" />
            </CardAction>
        </CardHeader>
        <CardContent v-if="enabled">
            <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                <Field :data-invalid="!!errors['regulation.transfer_type'] || undefined">
                    <FieldLabel for="transfer-type" class="gap-0.5"
                        >Tipo de traslado<span class="text-destructive">*</span></FieldLabel
                    >
                    <NativeSelect
                        id="transfer-type"
                        v-model="regulation.transfer_type"
                        container-class="w-full"
                        class="h-8 rounded-lg py-1 dark:bg-input/30"
                        :aria-invalid="!!errors['regulation.transfer_type'] || undefined"
                    >
                        <NativeSelectOption :value="null" disabled>Seleccionar</NativeSelectOption>
                        <NativeSelectOption v-for="value in transferTypeOptions" :key="value" :value="value">
                            {{ transferTypeLabel(value) }}
                        </NativeSelectOption>
                    </NativeSelect>
                    <FieldError
                        :errors="
                            errors['regulation.transfer_type']
                                ? [{ message: errors['regulation.transfer_type'] }]
                                : undefined
                        "
                    />
                </Field>
                <TextField
                    id="regulation-regulated-at"
                    v-model="regulation.regulated_at"
                    label="Fecha y hora de regulación"
                    type="datetime-local"
                    :errors="errors['regulation.regulated_at']"
                    required
                />
                <TextField
                    v-for="[field, label] in optionalFields"
                    :id="`regulation-${field}`"
                    :key="field"
                    v-model="regulation[field]"
                    :label="label"
                    sub-label="Opcional"
                    :errors="errors[`regulation.${field}`]"
                />
            </div>
        </CardContent>
    </Card>
</template>
