<script setup lang="ts">
import { UserX } from '@lucide/vue';
import { disable } from '@/actions/App/Http/Controllers/PatientController';
import ConsultationReasonDialog from '@/components/consultations/ConsultationReasonDialog.vue';

const props = defineProps<{ uuid: string; name: string }>();
const url = disable.url(props.uuid);
</script>

<template>
    <ConsultationReasonDialog
        :url="url"
        field-id="disable-patient-reason"
        trigger-label="Deshabilitar"
        trigger-test-id="disable-patient"
        :title="`Deshabilitar a ${name}`"
        submit-label="Deshabilitar"
        submitting-label="Deshabilitando..."
        destructive
    >
        <template #trigger-icon><UserX aria-hidden="true" /></template>
        <template #description>
            <p>
                El paciente dejará de poder editarse y no podrá recibir nuevas consultas. Sus datos y consultas no se
                eliminan, y puede habilitarse de nuevo más adelante.
            </p>
            <p>Indica el motivo de la deshabilitación (obligatorio).</p>
        </template>
    </ConsultationReasonDialog>
</template>
