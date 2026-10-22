<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ClipboardList, Pencil, Plus } from '@lucide/vue';
import {
    create as consultationCreate,
    show as consultationShow,
} from '@/actions/App/Http/Controllers/MedicalConsultationController';
import ChangeHistoryDialog from '@/components/history/ChangeHistoryDialog.vue';
import DisablePatientDialog from '@/components/patients/DisablePatientDialog.vue';
import EnablePatientDialog from '@/components/patients/EnablePatientDialog.vue';
import DisabledPatientBanner from '@/components/patients/DisabledPatientBanner.vue';
import PatientProfileSections from '@/components/patients/PatientProfileSections.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { history as patientHistory } from '@/actions/App/Http/Controllers/PatientController';
import AppLayout from '@/layouts/AppLayout.vue';
import { usePatientFlashToast } from '@/composables/usePatientFlashToast';
import { medicalClassificationLabel } from '@/lib/consultationLabels';
import { formatDateTime } from '@/lib/formatDateTime';
import { edit as patientEdit, index as patientsIndex } from '@/routes/patients';
import type { ConsultationListItem } from '@/types/consultations';
import type { PatientAbilities, PatientDetail } from '@/types/patients';

defineOptions({ layout: (h: any, page: any) => h(AppLayout, { title: 'Paciente' }, () => page) });

const props = defineProps<{
    patient: PatientDetail;
    consultations: ConsultationListItem[];
    can: PatientAbilities;
}>();

usePatientFlashToast();

function buildHistoryUrl(cursor: number | null): string {
    return patientHistory.url(props.patient.uuid, cursor === null ? undefined : { query: { cursor } });
}
</script>

<template>
    <Head :title="patient.full_name" />

    <div class="mx-auto flex w-full max-w-5xl flex-col gap-6">
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0 space-y-1">
                <h1 class="flex flex-wrap items-center gap-2 text-2xl font-semibold">
                    {{ patient.full_name }}
                    <Badge v-if="patient.is_disabled" variant="secondary">Deshabilitado</Badge>
                </h1>
                <p class="text-sm text-muted-foreground">
                    {{
                        patient.profile.enrollment_number
                            ? `Inscripción #${patient.profile.enrollment_number}`
                            : 'Sin número de inscripción'
                    }}
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <Button variant="outline" as-child><Link :href="patientsIndex().url">Volver a pacientes</Link></Button>
                <Button v-if="can.update" variant="outline" as-child>
                    <Link :href="patientEdit(patient.uuid).url"><Pencil aria-hidden="true" />Editar</Link>
                </Button>
                <DisablePatientDialog v-if="can.disable" :uuid="patient.uuid" :name="patient.full_name" />
                <EnablePatientDialog v-if="can.enable" :uuid="patient.uuid" :name="patient.full_name" />
                <ChangeHistoryDialog
                    v-if="can.audit"
                    :build-url="buildHistoryUrl"
                    :description="`Registro de cambios de ${patient.full_name}.`"
                    empty-message="Este paciente no tiene cambios registrados."
                    trigger-test-id="patient-history"
                />
                <Button v-if="can.createConsultation && !patient.is_disabled" as-child>
                    <Link :href="consultationCreate.url({ query: { patient: patient.uuid } })">
                        <Plus aria-hidden="true" />Nueva consulta
                    </Link>
                </Button>
            </div>
        </header>

        <DisabledPatientBanner
            v-if="patient.disabled"
            :disabled-at="patient.disabled.at"
            :disabled-by="patient.disabled.by"
            :reason="patient.disabled.reason"
        />

        <PatientProfileSections :profile="patient.profile" />

        <Card data-testid="section-consultations">
            <CardHeader>
                <CardTitle class="flex items-center gap-2">
                    Consultas
                    <Badge variant="secondary" :aria-label="`${consultations.length} consultas`">
                        {{ consultations.length }}
                    </Badge>
                </CardTitle>
                <CardDescription>Historial de consultas del paciente.</CardDescription>
            </CardHeader>
            <CardContent>
                <div
                    v-if="consultations.length === 0"
                    class="flex flex-col items-center gap-2 rounded-lg border border-dashed px-4 py-10 text-center"
                >
                    <ClipboardList class="size-8 text-muted-foreground" aria-hidden="true" />
                    <p class="font-medium">Este paciente no tiene consultas registradas.</p>
                </div>
                <Table v-else>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Código</TableHead>
                            <TableHead>Médico</TableHead>
                            <TableHead>Clasificación</TableHead>
                            <TableHead>Fecha</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="consultation in consultations" :key="consultation.uuid">
                            <TableCell>
                                <div class="flex items-center gap-2">
                                    <Link
                                        :href="consultationShow.url(consultation.uuid)"
                                        class="font-medium hover:underline"
                                    >
                                        {{ consultation.code }}
                                    </Link>
                                    <Badge v-if="consultation.voided_at" variant="destructive">Anulada</Badge>
                                </div>
                            </TableCell>
                            <TableCell>{{ consultation.physician.name }}</TableCell>
                            <TableCell>{{ medicalClassificationLabel(consultation.medical_classification) }}</TableCell>
                            <TableCell class="text-muted-foreground">{{
                                formatDateTime(consultation.created_at)
                            }}</TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </CardContent>
        </Card>
    </div>
</template>
