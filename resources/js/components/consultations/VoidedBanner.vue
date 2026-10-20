<script setup lang="ts">
import { Ban } from '@lucide/vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { formatDateTime } from '@/lib/formatDateTime';

defineProps<{
    voidedAt: string;
    voidedBy: string | null;
    reason: string | null;
}>();
</script>

<template>
    <Alert variant="destructive" data-testid="voided-banner">
        <Ban aria-hidden="true" />
        <AlertTitle>Anulada</AlertTitle>
        <AlertDescription>
            <p>
                Anulada el {{ formatDateTime(voidedAt) }}<template v-if="voidedBy"> por {{ voidedBy }}</template
                >.
            </p>
            <p v-if="reason">Motivo: {{ reason }}</p>
        </AlertDescription>
    </Alert>
</template>
