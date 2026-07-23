<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import ConsultationList from '@/components/consultations/ConsultationList.vue';
import PatientSearch from '@/components/consultations/PatientSearch.vue';
import PatientSummaryDialog from '@/components/consultations/PatientSummaryDialog.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { ConsultationListItem, PatientSearchResult } from '@/types/consultations';

defineOptions({
    layout: (h: any, page: any) => h(AppLayout, { title: 'Panel' }, () => page),
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

/** `action` defaults to `created` because today only `store` flashes, and without the field yet (see `types/consultations.ts`). */
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

        const actionLabel = CONSULTATION_ACTION_LABELS[consultation.action ?? 'created'] ?? 'registrada';
        toast.success(`Consulta ${consultation.code} ${actionLabel}`);
    },
    { immediate: true },
);
</script>

<template>
    <Head title="Panel" />

    <div class="space-y-6">
        <h1 class="text-2xl font-semibold">Panel</h1>

        <PatientSearch v-if="can.searchPatients" @select="openSummary" />

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
