<script setup lang="ts">
import { UserX } from '@lucide/vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { formatDateTime } from '@/lib/formatDateTime';

defineProps<{
    disabledAt: string;
    disabledBy: string | null;
    reason: string | null;
}>();
</script>

<template>
    <Alert variant="destructive" data-testid="disabled-patient-banner">
        <UserX aria-hidden="true" />
        <AlertTitle>Paciente deshabilitado</AlertTitle>
        <AlertDescription>
            <p>
                Deshabilitado el {{ formatDateTime(disabledAt)
                }}<template v-if="disabledBy"> por {{ disabledBy }}</template
                >.
            </p>
            <p v-if="reason">Motivo: {{ reason }}</p>
        </AlertDescription>
    </Alert>
</template>
