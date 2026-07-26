<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { medicalClassificationLabel, medicalStateLabel, transferTypeLabel } from '@/lib/consultationLabels';
import type { ConsultationAggregate, PhysicalExaminationForm, VitalSignsForm } from '@/types/consultations';
defineProps<{ consultation: ConsultationAggregate }>();
const vitalFields: [keyof VitalSignsForm, string, string][] = [
    ['weight', 'Peso', 'kg'],
    ['height', 'Talla', 'm'],
    ['heart_rate', 'FC', 'lpm'],
    ['respiratory_rate', 'FR', 'rpm'],
    ['temperature', 'Temperatura', '°C'],
    ['oxygen_saturation', 'SpO2', '%'],
    ['glasgow', 'Glasgow', ''],
    ['glucose', 'Glucosa', 'mg/dL'],
];
const examinationFields: [keyof PhysicalExaminationForm, string][] = [
    ['neurological', 'Neurológico'],
    ['head_neck', 'Cabeza y cuello'],
    ['thorax_cardiopulmonary', 'Tórax y cardiopulmonar'],
    ['abdomen', 'Abdomen'],
    ['extremities', 'Extremidades'],
    ['cabinet_laboratory', 'Gabinete y laboratorio'],
];
const NOT_RECORDED = 'No registrado';
const dtClass = 'text-sm text-muted-foreground';
const ddClass = 'mt-1 font-medium whitespace-pre-line';
</script>

<template>
    <div class="flex flex-col gap-6">
        <Card>
            <CardHeader><CardTitle>Consulta</CardTitle></CardHeader>
            <CardContent>
                <dl class="grid gap-4 md:grid-cols-2">
                    <div>
                        <dt :class="dtClass">Padecimiento actual</dt>
                        <dd :class="ddClass">{{ consultation.consultation.current_condition }}</dd>
                    </div>
                    <div>
                        <dt :class="dtClass">Diagnóstico</dt>
                        <dd :class="ddClass">{{ consultation.consultation.diagnosis }}</dd>
                    </div>
                </dl>
                <dl class="mt-4 grid gap-4 border-t pt-4 md:grid-cols-3">
                    <div>
                        <dt :class="dtClass">Estado</dt>
                        <dd class="mt-1">
                            <Badge variant="outline">{{ medicalStateLabel(consultation.condition!) }}</Badge>
                        </dd>
                    </div>
                    <div>
                        <dt :class="dtClass">Pronóstico</dt>
                        <dd class="mt-1">
                            <Badge variant="outline">{{ medicalStateLabel(consultation.prognosis!) }}</Badge>
                        </dd>
                    </div>
                    <div>
                        <dt :class="dtClass">Clasificación médica</dt>
                        <dd class="mt-1">
                            <Badge variant="secondary">{{
                                medicalClassificationLabel(consultation.medical_classification!)
                            }}</Badge>
                        </dd>
                    </div>
                </dl>
            </CardContent>
        </Card>

        <Card>
            <CardHeader><CardTitle>Signos vitales</CardTitle></CardHeader>
            <CardContent>
                <dl class="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-5">
                    <div>
                        <dt :class="dtClass">TA</dt>
                        <dd :class="ddClass">
                            {{ consultation.vital_signs.blood_pressure_systolic ?? '–' }}/{{
                                consultation.vital_signs.blood_pressure_diastolic ?? '–'
                            }}
                            <span class="text-sm font-normal text-muted-foreground">mmHg</span>
                        </dd>
                    </div>
                    <div v-for="[field, label, unit] in vitalFields" :key="field">
                        <dt :class="dtClass">{{ label }}</dt>
                        <dd :class="ddClass">
                            <template
                                v-if="
                                    consultation.vital_signs[field] !== null && consultation.vital_signs[field] !== ''
                                "
                            >
                                {{ consultation.vital_signs[field] }}
                                <span v-if="unit" class="text-sm font-normal text-muted-foreground">{{ unit }}</span>
                            </template>
                            <span v-else class="font-normal text-muted-foreground">{{ NOT_RECORDED }}</span>
                        </dd>
                    </div>
                </dl>
            </CardContent>
        </Card>

        <Card>
            <CardHeader><CardTitle>Exploración física</CardTitle></CardHeader>
            <CardContent>
                <dl class="grid gap-4 md:grid-cols-2">
                    <div v-for="[field, label] in examinationFields" :key="field">
                        <dt :class="dtClass">{{ label }}</dt>
                        <dd :class="ddClass">{{ consultation.physical_examination[field] }}</dd>
                    </div>
                </dl>
            </CardContent>
        </Card>

        <Card>
            <CardHeader><CardTitle>Tratamiento</CardTitle></CardHeader>
            <CardContent>
                <p
                    v-if="consultation.treatment.length === 0"
                    class="rounded-lg border border-dashed px-4 py-6 text-center text-muted-foreground"
                >
                    Sin medicamentos
                </p>
                <ul v-else class="divide-y rounded-lg border">
                    <li
                        v-for="(row, index) in consultation.treatment"
                        :key="index"
                        class="grid gap-2 p-3 md:grid-cols-[minmax(0,2fr)_repeat(3,minmax(0,1fr))]"
                    >
                        <span class="font-medium">{{ row.medication }}</span>
                        <span><span class="text-muted-foreground">Dosis:</span> {{ row.dose }}</span>
                        <span><span class="text-muted-foreground">Frecuencia:</span> {{ row.frequency }}</span>
                        <span><span class="text-muted-foreground">Duración:</span> {{ row.duration }}</span>
                    </li>
                </ul>
            </CardContent>
        </Card>

        <Card>
            <CardHeader><CardTitle>Regulación médica</CardTitle></CardHeader>
            <CardContent>
                <dl v-if="consultation.regulation" class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <dt :class="dtClass">Tipo de traslado</dt>
                        <dd :class="ddClass">{{ transferTypeLabel(consultation.regulation.transfer_type!) }}</dd>
                    </div>
                    <div>
                        <dt :class="dtClass">Fecha y hora de regulación</dt>
                        <dd :class="ddClass">{{ consultation.regulation.regulated_at }}</dd>
                    </div>
                    <div>
                        <dt :class="dtClass">Registro de ambulancia</dt>
                        <dd :class="ddClass">{{ consultation.regulation.ambulance_registration || NOT_RECORDED }}</dd>
                    </div>
                    <div>
                        <dt :class="dtClass">Número de regulación</dt>
                        <dd :class="ddClass">{{ consultation.regulation.regulation_number || NOT_RECORDED }}</dd>
                    </div>
                    <div>
                        <dt :class="dtClass">Clínica</dt>
                        <dd :class="ddClass">{{ consultation.regulation.clinic_id || NOT_RECORDED }}</dd>
                    </div>
                    <div>
                        <dt :class="dtClass">Médico receptor</dt>
                        <dd :class="ddClass">{{ consultation.regulation.receiver_physician || NOT_RECORDED }}</dd>
                    </div>
                </dl>
                <p v-else class="text-muted-foreground">Sin regulación médica.</p>
            </CardContent>
        </Card>
    </div>
</template>
