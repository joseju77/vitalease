<script setup lang="ts">
import { history as historyAction } from '@/actions/App/Http/Controllers/MedicalConsultationController';
import ChangeHistoryDialog from '@/components/history/ChangeHistoryDialog.vue';

const props = defineProps<{ uuid: string; code: string }>();

function buildUrl(cursor: number | null): string {
    return historyAction.url(props.uuid, cursor === null ? undefined : { query: { cursor } });
}
</script>

<template>
    <ChangeHistoryDialog
        :build-url="buildUrl"
        :description="`Registro de cambios de la consulta ${code}.`"
        empty-message="Esta consulta no tiene cambios registrados."
    />
</template>
