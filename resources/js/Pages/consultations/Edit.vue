<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { show } from '@/actions/App/Http/Controllers/MedicalConsultationController';
import ConsultationForm from '@/components/consultations/ConsultationForm.vue';
import DeleteConsultationDialog from '@/components/consultations/DeleteConsultationDialog.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import type { ConsultationAggregate, ConsultationFormOptions } from '@/types/consultations';

defineOptions({ layout: (h: any, page: any) => h(AppLayout, { title: 'Editar consulta' }, () => page) });
defineProps<ConsultationFormOptions & { consultation: ConsultationAggregate }>();
</script>

<template>
    <Head :title="`Editar ${consultation.code}`" />
    <ConsultationForm
        mode="edit"
        :title="`Editar ${consultation.code}`"
        :patient="consultation.patient"
        :consultation="consultation"
        :medical-state-options="medicalStateOptions"
        :medical-classification-options="medicalClassificationOptions"
        :transfer-type-options="transferTypeOptions"
    >
        <template #header-actions>
            <Button variant="outline" as-child><Link :href="show.url(consultation.uuid)">Ver detalle</Link></Button>
        </template>
        <template v-if="consultation.can.delete" #footer-start>
            <DeleteConsultationDialog :uuid="consultation.uuid" :code="consultation.code" />
        </template>
    </ConsultationForm>
</template>
