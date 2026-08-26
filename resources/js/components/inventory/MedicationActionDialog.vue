<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed } from 'vue';
import { toast } from 'vue-sonner';
import { deactivate, destroy } from '@/routes/inventory';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import type { ButtonVariants } from '@/components/ui/button';
import type { Medication } from '@/types/inventory';

/**
 * Confirms a destructive-ish medication mutation: deactivate (reversible,
 * keeps history) or delete (irreversible, only offered when the server-side
 * `can_be_deleted` flag allows it). Activation is not handled here — per
 * design D4 it needs no confirmation and is dispatched directly by the
 * caller. Shared by `Pages/inventory/Index.vue` and, later, `Show.vue`.
 */
const props = defineProps<{
    medication: Medication;
    action: 'deactivate' | 'delete';
}>();

const open = defineModel<boolean>('open', { default: false });

const content = computed(
    (): { title: string; description: string; confirmLabel: string; confirmVariant: ButtonVariants['variant'] } => {
        if (props.action === 'deactivate') {
            return {
                title: 'Desactivar medicamento',
                description: `¿Seguro que quieres desactivar "${props.medication.name}"? Podrás activarlo de nuevo cuando lo necesites; su historial de movimientos se conserva.`,
                confirmLabel: 'Desactivar',
                confirmVariant: 'default',
            };
        }

        return {
            title: 'Eliminar medicamento',
            description: `¿Seguro que quieres eliminar "${props.medication.name}"? Esta acción no se puede deshacer.`,
            confirmLabel: 'Eliminar',
            confirmVariant: 'destructive',
        };
    },
);

/**
 * `has_history` safety net: the server may still reject a delete with a 422
 * on the `medication` field (e.g. a movement was recorded concurrently after
 * the list computed `can_be_deleted`). Surface it as a toast and leave the
 * dialog open instead of closing on a failed request.
 */
function onError(errors: Record<string, string>) {
    if (errors.medication) {
        toast.error(errors.medication);
    }
}

function onSuccess() {
    open.value = false;
}

function confirm() {
    if (props.action === 'deactivate') {
        router.patch(deactivate(props.medication.uuid).url, {}, { preserveScroll: true, onSuccess, onError });
        return;
    }

    router.delete(destroy(props.medication.uuid).url, { preserveScroll: true, onSuccess, onError });
}
</script>

<template>
    <AlertDialog v-model:open="open">
        <AlertDialogContent>
            <AlertDialogHeader>
                <AlertDialogTitle>{{ content.title }}</AlertDialogTitle>
                <AlertDialogDescription>{{ content.description }}</AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
                <AlertDialogCancel>Cancelar</AlertDialogCancel>
                <Button :variant="content.confirmVariant" @click="confirm">{{ content.confirmLabel }}</Button>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>
</template>
