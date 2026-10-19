<script setup lang="ts">
import { Ban } from '@lucide/vue';
import { voidConsultation } from '@/actions/App/Http/Controllers/MedicalConsultationController';
import ConsultationReasonDialog from '@/components/consultations/ConsultationReasonDialog.vue';

const props = defineProps<{ uuid: string; code: string }>();
const url = voidConsultation.url(props.uuid);
</script>

<template>
    <ConsultationReasonDialog
        :url="url"
        field-id="void-consultation-reason"
        trigger-label="Anular consulta"
        :title="`Anular ${code}`"
        submit-label="Anular"
        submitting-label="Anulando..."
        destructive
    >
        <template #trigger-icon><Ban aria-hidden="true" /></template>
        <template #description>
            <p>
                Se anulará la consulta y se devolverá al inventario la existencia de los medicamentos surtidos. La
                consulta no se elimina y puede restaurarse más adelante.
            </p>
            <p>Indica el motivo de la anulación (obligatorio).</p>
        </template>
    </ConsultationReasonDialog>
</template>
