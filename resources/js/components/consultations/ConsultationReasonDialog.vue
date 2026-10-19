<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import TextareaField from '@/components/form/TextareaField.vue';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';

/**
 * Shared confirmation dialog for the consultation void and restore actions:
 * a required reason (max 500 characters) posted to `url`. The dialog stays
 * open on a validation error and lists every non-reason server error (the
 * `consultation` state error and the per-line `treatment.{n}` stock errors).
 */
const props = defineProps<{
    url: string;
    fieldId: string;
    triggerLabel: string;
    title: string;
    submitLabel: string;
    submittingLabel: string;
    destructive?: boolean;
}>();

const REASON_MAX_LENGTH = 500;

const open = ref(false);
const clientError = ref('');
const form = useForm({ reason: '' });

watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    clientError.value = '';
    form.clearErrors();
    form.reset();
});

const reasonError = computed(() => clientError.value || form.errors.reason);
const otherErrors = computed(() =>
    Object.entries(form.errors)
        .filter(([key]) => key !== 'reason')
        .map(([, message]) => message),
);

function submit() {
    const reason = form.reason.trim();

    if (reason === '') {
        clientError.value = 'El motivo es obligatorio.';
        return;
    }

    if (reason.length > REASON_MAX_LENGTH) {
        clientError.value = `El motivo no puede superar los ${REASON_MAX_LENGTH} caracteres.`;
        return;
    }

    clientError.value = '';
    form.reason = reason;
    form.post(props.url, {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
}
</script>

<template>
    <AlertDialog v-model:open="open">
        <AlertDialogTrigger as-child>
            <Button type="button" :variant="destructive ? 'destructive' : 'outline'">
                <slot name="trigger-icon" />{{ triggerLabel }}
            </Button>
        </AlertDialogTrigger>
        <AlertDialogContent>
            <form class="space-y-4" @submit.prevent="submit">
                <AlertDialogHeader>
                    <AlertDialogTitle>{{ title }}</AlertDialogTitle>
                    <AlertDialogDescription as-child>
                        <div class="space-y-2"><slot name="description" /></div>
                    </AlertDialogDescription>
                </AlertDialogHeader>

                <TextareaField :id="fieldId" v-model="form.reason" label="Motivo" :errors="reasonError" required />

                <ul
                    v-if="otherErrors.length > 0"
                    role="alert"
                    class="ml-4 list-disc space-y-1 text-sm text-destructive"
                >
                    <li v-for="(message, index) in otherErrors" :key="index">{{ message }}</li>
                </ul>

                <AlertDialogFooter>
                    <AlertDialogCancel type="button">Cancelar</AlertDialogCancel>
                    <Button
                        type="submit"
                        :variant="destructive ? 'destructive' : 'default'"
                        :disabled="form.processing"
                    >
                        {{ form.processing ? submittingLabel : submitLabel }}
                    </Button>
                </AlertDialogFooter>
            </form>
        </AlertDialogContent>
    </AlertDialog>
</template>
