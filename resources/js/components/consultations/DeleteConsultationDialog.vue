<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Trash2 } from '@lucide/vue';
import { destroy } from '@/actions/App/Http/Controllers/MedicalConsultationController';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
const props = defineProps<{ uuid: string; code: string }>();
function remove() {
    router.delete(destroy.url(props.uuid));
}
</script>

<template>
    <AlertDialog
        ><AlertDialogTrigger as-child
            ><Button type="button" variant="destructive"
                ><Trash2 aria-hidden="true" />Eliminar consulta</Button
            ></AlertDialogTrigger
        >
        <AlertDialogContent
            ><AlertDialogHeader
                ><AlertDialogTitle>Eliminar {{ code }}</AlertDialogTitle
                ><AlertDialogDescription
                    >Esta acción no se puede deshacer. Se eliminará la consulta y sus datos
                    asociados.</AlertDialogDescription
                ></AlertDialogHeader
            >
            <AlertDialogFooter
                ><AlertDialogCancel>Cancelar</AlertDialogCancel
                ><AlertDialogAction @click="remove">Eliminar</AlertDialogAction></AlertDialogFooter
            >
        </AlertDialogContent>
    </AlertDialog>
</template>
