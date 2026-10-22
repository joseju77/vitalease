<script setup lang="ts">
import { UserCheck } from '@lucide/vue';
import { enable } from '@/actions/App/Http/Controllers/PatientController';
import ConsultationReasonDialog from '@/components/consultations/ConsultationReasonDialog.vue';

const props = defineProps<{ uuid: string; name: string }>();
const url = enable.url(props.uuid);
</script>

<template>
    <ConsultationReasonDialog
        :url="url"
        field-id="enable-patient-reason"
        trigger-label="Habilitar"
        trigger-test-id="enable-patient"
        :title="`Habilitar a ${name}`"
        submit-label="Habilitar"
        submitting-label="Habilitando..."
    >
        <template #trigger-icon><UserCheck aria-hidden="true" /></template>
        <template #description>
            <p>El paciente volverá a poder editarse y recibir nuevas consultas.</p>
            <p>Indica el motivo de la habilitación (obligatorio).</p>
        </template>
    </ConsultationReasonDialog>
</template>
