<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { index as dashboard } from '@/actions/App/Http/Controllers/DashboardController';
import { store, update } from '@/actions/App/Http/Controllers/MedicalConsultationController';
import TextareaField from '@/components/form/TextareaField.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select';
import { medicalClassificationLabel, medicalStateLabel } from '@/lib/consultationLabels';
import type {
    ConsultationAggregate,
    ConsultationFormOptions,
    ConsultationFormPayload,
    ConsultationPatient,
    RegulationForm,
} from '@/types/consultations';
import TreatmentRows from './TreatmentRows.vue';
import VitalSignsSection from './VitalSignsSection.vue';
import PhysicalExaminationSection from './PhysicalExaminationSection.vue';
import RegulationSection from './RegulationSection.vue';
import ConsultationPageHeader from './ConsultationPageHeader.vue';

const props = defineProps<
    ConsultationFormOptions & {
        mode: 'create' | 'edit';
        title: string;
        patient: ConsultationPatient;
        consultation?: ConsultationAggregate;
    }
>();
const defaultRegulation: RegulationForm = {
    transfer_type: null,
    regulated_at: '',
    ambulance_registration: '',
    regulation_number: '',
    clinic_id: '',
    receiver_physician: '',
};
const hasRegulation = ref(!!props.consultation?.regulation);
const form = useForm<ConsultationFormPayload>({
    ...(props.mode === 'create' ? { patient_uuid: props.patient.uuid } : {}),
    consultation: {
        current_condition: props.consultation?.consultation.current_condition ?? '',
        diagnosis: props.consultation?.consultation.diagnosis ?? '',
    },
    condition: props.consultation?.condition ?? null,
    prognosis: props.consultation?.prognosis ?? null,
    medical_classification: props.consultation?.medical_classification ?? null,
    treatment: props.consultation?.treatment.map((row) => ({ ...row })) ?? [],
    vital_signs: {
        weight: String(props.consultation?.vital_signs.weight ?? ''),
        height: String(props.consultation?.vital_signs.height ?? ''),
        blood_pressure_systolic: String(props.consultation?.vital_signs.blood_pressure_systolic ?? ''),
        blood_pressure_diastolic: String(props.consultation?.vital_signs.blood_pressure_diastolic ?? ''),
        heart_rate: String(props.consultation?.vital_signs.heart_rate ?? ''),
        respiratory_rate: String(props.consultation?.vital_signs.respiratory_rate ?? ''),
        temperature: String(props.consultation?.vital_signs.temperature ?? ''),
        oxygen_saturation: String(props.consultation?.vital_signs.oxygen_saturation ?? ''),
        glasgow: String(props.consultation?.vital_signs.glasgow ?? ''),
        glucose: String(props.consultation?.vital_signs.glucose ?? ''),
    },
    physical_examination: {
        neurological: props.consultation?.physical_examination.neurological ?? '',
        head_neck: props.consultation?.physical_examination.head_neck ?? '',
        thorax_cardiopulmonary: props.consultation?.physical_examination.thorax_cardiopulmonary ?? '',
        abdomen: props.consultation?.physical_examination.abdomen ?? '',
        extremities: props.consultation?.physical_examination.extremities ?? '',
        cabinet_laboratory: props.consultation?.physical_examination.cabinet_laboratory ?? '',
    },
    regulation: { ...defaultRegulation, ...props.consultation?.regulation },
});

function submit() {
    if (form.processing) return;
    form.transform((data) => ({
        ...data,
        vital_signs: {
            ...data.vital_signs,
            glucose: data.vital_signs.glucose === '' ? null : data.vital_signs.glucose,
        },
        regulation: hasRegulation.value ? data.regulation : null,
    }));
    if (props.mode === 'create') form.post(store().url);
    else if (props.consultation) form.put(update.url(props.consultation.uuid));
}
/** Read through `computed`: Inertia's `clearErrors()` replaces `form.errors` with a new object on every failed submit. */
const errors = computed(() => form.errors as Record<string, string>);
const selectFields = [
    ['condition', 'Estado'],
    ['prognosis', 'Pronóstico'],
    ['medical_classification', 'Clasificación médica'],
] as const;
</script>

<template>
    <form class="flex flex-1 flex-col gap-6" @submit.prevent="submit">
        <ConsultationPageHeader :title="title" :patient="patient">
            <template v-if="$slots['header-actions']" #actions><slot name="header-actions" /></template>
        </ConsultationPageHeader>

        <div class="mx-auto flex w-full max-w-5xl flex-col gap-6">
            <slot name="before-sections" />
            <Card>
                <CardHeader>
                    <CardTitle>Consulta</CardTitle>
                    <CardDescription>Motivo de la consulta, diagnóstico y valoración general.</CardDescription>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div class="grid gap-4 md:grid-cols-2">
                        <TextareaField
                            id="current-condition"
                            v-model="form.consultation.current_condition"
                            label="Padecimiento actual"
                            :errors="errors['consultation.current_condition']"
                            required
                        />
                        <TextareaField
                            id="diagnosis"
                            v-model="form.consultation.diagnosis"
                            label="Diagnóstico"
                            :errors="errors['consultation.diagnosis']"
                            required
                        />
                    </div>
                    <div class="grid gap-4 md:grid-cols-3">
                        <Field
                            v-for="[field, label] in selectFields"
                            :key="field"
                            :data-invalid="!!errors[field] || undefined"
                        >
                            <FieldLabel :for="field" class="gap-0.5"
                                >{{ label }}<span class="text-destructive">*</span></FieldLabel
                            >
                            <NativeSelect
                                :id="field"
                                v-model="form[field]"
                                container-class="w-full"
                                class="h-8 rounded-lg py-1 dark:bg-input/30"
                                :aria-invalid="!!errors[field] || undefined"
                            >
                                <NativeSelectOption :value="null" disabled>Seleccionar</NativeSelectOption>
                                <NativeSelectOption
                                    v-for="value in field === 'medical_classification'
                                        ? medicalClassificationOptions
                                        : medicalStateOptions"
                                    :key="value"
                                    :value="value"
                                >
                                    {{
                                        field === 'medical_classification'
                                            ? medicalClassificationLabel(value)
                                            : medicalStateLabel(value)
                                    }}
                                </NativeSelectOption>
                            </NativeSelect>
                            <FieldError :errors="errors[field] ? [{ message: errors[field] }] : undefined" />
                        </Field>
                    </div>
                </CardContent>
            </Card>

            <VitalSignsSection v-model="form.vital_signs" :errors="errors" />
            <PhysicalExaminationSection v-model="form.physical_examination" :errors="errors" />
            <TreatmentRows v-model="form.treatment" :errors="errors" />
            <RegulationSection
                v-model:enabled="hasRegulation"
                v-model:regulation="form.regulation!"
                :errors="errors"
                :transfer-type-options="transferTypeOptions"
            />
        </div>

        <div
            class="sticky bottom-0 z-20 -mx-6 mt-auto -mb-6 border-t border-border/70 bg-background/95 px-6 py-3 backdrop-blur supports-[backdrop-filter]:bg-background/80"
        >
            <div class="mx-auto flex w-full max-w-5xl flex-wrap items-center gap-3">
                <slot name="footer-start" />
                <div class="ml-auto flex items-center gap-3">
                    <Button variant="outline" as-child><Link :href="dashboard.url()">Cancelar</Link></Button>
                    <Button type="submit" :disabled="form.processing">{{
                        form.processing ? 'Guardando…' : 'Guardar consulta'
                    }}</Button>
                </div>
            </div>
        </div>
    </form>
</template>
