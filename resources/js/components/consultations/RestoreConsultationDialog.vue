<script setup lang="ts">
import { Undo2 } from '@lucide/vue';
import { restore } from '@/actions/App/Http/Controllers/MedicalConsultationController';
import ConsultationReasonDialog from '@/components/consultations/ConsultationReasonDialog.vue';

const props = defineProps<{ uuid: string; code: string }>();
const url = restore.url(props.uuid);
</script>

<template>
    <ConsultationReasonDialog
        :url="url"
        field-id="restore-consultation-reason"
        trigger-label="Restaurar consulta"
        :title="`Restaurar ${code}`"
        submit-label="Restaurar"
        submitting-label="Restaurando..."
    >
        <template #trigger-icon><Undo2 aria-hidden="true" /></template>
        <template #description>
            <p>
                Se restaurará la consulta y se volverán a surtir los medicamentos de su tratamiento. Si no hay
                existencia suficiente de alguno, la consulta seguirá anulada.
            </p>
            <p>Indica el motivo de la restauración (obligatorio).</p>
        </template>
    </ConsultationReasonDialog>
</template>
