<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { medicalClassificationLabel } from '@/lib/consultationLabels';
import { formatDateTime } from '@/lib/formatDateTime';
import type { ConsultationListItem } from '@/types/consultations';

defineProps<{
    consultations: ConsultationListItem[];
}>();

/**
 * `consultations.show` is only registered starting in Work Unit 2; this
 * builds the matching plain URL ahead of time (see `PatientSummaryDialog.vue`
 * for the same decision) so the list works the moment that route lands.
 */
function consultationHref(uuid: string): string {
    return `/consultations/${uuid}`;
}
</script>

<template>
    <div class="space-y-2">
        <h2 class="text-lg font-semibold">Mis consultas recientes</h2>
        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>Código</TableHead>
                    <TableHead>Paciente</TableHead>
                    <TableHead>Clasificación</TableHead>
                    <TableHead>Fecha</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                <TableEmpty v-if="consultations.length === 0" :colspan="4">
                    No tienes consultas registradas.
                </TableEmpty>
                <TableRow v-for="consultation in consultations" :key="consultation.uuid">
                    <TableCell>
                        <Link :href="consultationHref(consultation.uuid)" class="font-medium hover:underline">
                            {{ consultation.code }}
                        </Link>
                    </TableCell>
                    <TableCell>{{ consultation.patient.full_name }}</TableCell>
                    <TableCell>{{ medicalClassificationLabel(consultation.medical_classification) }}</TableCell>
                    <TableCell>{{ formatDateTime(consultation.created_at) }}</TableCell>
                </TableRow>
            </TableBody>
        </Table>
    </div>
</template>
