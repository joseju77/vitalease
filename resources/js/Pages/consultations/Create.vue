<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import ConsultationForm from '@/components/consultations/ConsultationForm.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { ConsultationFormOptions, ConsultationPatient } from '@/types/consultations';

defineOptions({ layout: (h: any, page: any) => h(AppLayout, { title: 'Nueva consulta' }, () => page) });
defineProps<ConsultationFormOptions & { patient: ConsultationPatient }>();
</script>

<template>
    <Head title="Nueva consulta" />
    <ConsultationForm
        mode="create"
        title="Nueva consulta"
        :patient="patient"
        :medical-state-options="medicalStateOptions"
        :medical-classification-options="medicalClassificationOptions"
        :transfer-type-options="transferTypeOptions"
    />
</template>
