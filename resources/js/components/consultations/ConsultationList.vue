<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ClipboardList } from '@lucide/vue';
import { show } from '@/actions/App/Http/Controllers/MedicalConsultationController';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { medicalClassificationLabel } from '@/lib/consultationLabels';
import { formatDateTime } from '@/lib/formatDateTime';
import type { ConsultationListItem } from '@/types/consultations';

defineProps<{
    consultations: ConsultationListItem[];
}>();

function consultationHref(uuid: string): string {
    return show.url(uuid);
}
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle class="flex items-center gap-2">
                Mis últimas consultas
                <Badge variant="secondary" :aria-label="`${consultations.length} consultas`">
                    {{ consultations.length }}
                </Badge>
            </CardTitle>
            <CardDescription>Consultas que registraste recientemente.</CardDescription>
        </CardHeader>
        <CardContent>
            <div
                v-if="consultations.length === 0"
                class="flex flex-col items-center gap-2 rounded-lg border border-dashed px-4 py-10 text-center"
            >
                <ClipboardList class="size-8 text-muted-foreground" aria-hidden="true" />
                <p class="font-medium">No tienes consultas registradas.</p>
                <p class="text-sm text-muted-foreground">Busca un paciente para registrar su primera consulta.</p>
            </div>
            <Table v-else>
                <TableHeader>
                    <TableRow>
                        <TableHead>Código</TableHead>
                        <TableHead>Paciente</TableHead>
                        <TableHead>Clasificación</TableHead>
                        <TableHead>Fecha</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="consultation in consultations" :key="consultation.uuid">
                        <TableCell>
                            <Link :href="consultationHref(consultation.uuid)" class="font-medium hover:underline">
                                {{ consultation.code }}
                            </Link>
                        </TableCell>
                        <TableCell>{{ consultation.patient.full_name }}</TableCell>
                        <TableCell>{{ medicalClassificationLabel(consultation.medical_classification) }}</TableCell>
                        <TableCell class="text-muted-foreground">{{
                            formatDateTime(consultation.created_at)
                        }}</TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </CardContent>
    </Card>
</template>
