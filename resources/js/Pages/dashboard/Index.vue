<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import ConsultationList from '@/components/consultations/ConsultationList.vue';
import PatientSearch from '@/components/consultations/PatientSearch.vue';
import PatientSummaryDialog from '@/components/consultations/PatientSummaryDialog.vue';
import { Card, CardContent } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import type { ConsultationListItem, PatientSearchResult } from '@/types/consultations';

defineOptions({
    layout: (h: any, page: any) => h(AppLayout, { title: 'Consultas' }, () => page),
});

defineProps<{
    latestConsultations: ConsultationListItem[];
    can: {
        searchPatients: boolean;
        createConsultation: boolean;
    };
}>();

const page = usePage();

const selectedPatient = ref<PatientSearchResult | null>(null);
const summaryOpen = ref(false);

function openSummary(patient: PatientSearchResult) {
    selectedPatient.value = patient;
    summaryOpen.value = true;
}

const CONSULTATION_ACTION_LABELS: Record<string, string> = {
    created: 'registrada',
    updated: 'actualizada',
    deleted: 'eliminada',
};

watch(
    () => page.flash?.consultation,
    (consultation) => {
        if (!consultation) {
            return;
        }

        const actionLabel = CONSULTATION_ACTION_LABELS[consultation.action] ?? 'registrada';
        toast.success(`Consulta ${consultation.code} ${actionLabel}`);
    },
    { immediate: true },
);
</script>

<template>
    <Head title="Consultas" />

    <div class="mx-auto flex w-full max-w-5xl flex-col gap-6">
        <div class="space-y-1">
            <h1 class="text-2xl font-semibold">Consultas</h1>
            <p class="text-sm text-muted-foreground">
                Busca un paciente para ver su historial o registrar una nueva consulta.
            </p>
        </div>

        <Card v-if="can.searchPatients" class="overflow-visible ring-primary/25">
            <CardContent>
                <PatientSearch @select="openSummary" />
            </CardContent>
        </Card>

        <ConsultationList :consultations="latestConsultations" />
    </div>

    <PatientSummaryDialog
        v-if="selectedPatient"
        :key="selectedPatient.uuid"
        v-model:open="summaryOpen"
        :patient="selectedPatient"
        :can-create-consultation="can.createConsultation"
    />
</template>
