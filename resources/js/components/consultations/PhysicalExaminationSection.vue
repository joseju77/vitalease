<script setup lang="ts">
import TextareaField from '@/components/form/TextareaField.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import type { PhysicalExaminationForm } from '@/types/consultations';
const examination = defineModel<PhysicalExaminationForm>({ required: true });
defineProps<{ errors: Record<string, string> }>();
const fields = [
    ['neurological', 'Neurológico'],
    ['head_neck', 'Cabeza y cuello'],
    ['thorax_cardiopulmonary', 'Tórax y cardiopulmonar'],
    ['abdomen', 'Abdomen'],
    ['extremities', 'Extremidades'],
    ['cabinet_laboratory', 'Gabinete y laboratorio'],
] as const;
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle>Exploración física</CardTitle>
            <CardDescription>Hallazgos por aparato y sistema.</CardDescription>
        </CardHeader>
        <CardContent>
            <div class="grid gap-4 md:grid-cols-2">
                <TextareaField
                    v-for="[field, label] in fields"
                    :id="`examination-${field}`"
                    :key="field"
                    v-model="examination[field]"
                    :label="label"
                    :errors="errors[`physical_examination.${field}`]"
                    required
                />
            </div>
        </CardContent>
    </Card>
</template>
