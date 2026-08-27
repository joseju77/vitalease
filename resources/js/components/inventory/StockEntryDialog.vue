<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { store as storeEntry } from '@/routes/inventory/entries';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { FieldGroup, FieldSet } from '@/components/ui/field';
import TextField from '@/components/form/TextField.vue';
import TextareaField from '@/components/form/TextareaField.vue';
import type { Medication } from '@/types/inventory';

const props = defineProps<{
    medication: Medication;
}>();

const open = defineModel<boolean>('open', { default: false });

function defaultValues() {
    return {
        quantity: 1,
        notes: '',
    };
}

const form = useForm(defaultValues());

/**
 * Refill the form with fresh defaults every time the dialog is opened, so a
 * previous entry never leaks into the next one — matches
 * `MedicationFormDialog.vue`'s convention.
 */
watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    form.clearErrors();
    form.defaults(defaultValues());
    form.reset();
});

const quantityErrors = computed(() => form.errors.quantity);
const notesErrors = computed(() => form.errors.notes);

function submit() {
    form.post(storeEntry(props.medication.uuid).url, {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Registrar entrada</DialogTitle>
                <DialogDescription>
                    Registra una entrada de stock para {{ medication.name }}. Existencia actual:
                    {{ medication.current_stock }}.
                </DialogDescription>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="submit">
                <FieldSet>
                    <FieldGroup class="gap-4">
                        <TextField
                            v-model="form.quantity"
                            label="Cantidad"
                            :errors="quantityErrors"
                            hide-required-asterisk
                            type="number"
                            min="1"
                            required
                        />
                        <TextareaField
                            id="stock-entry-notes"
                            v-model="form.notes"
                            label="Notas"
                            :errors="notesErrors"
                        />
                    </FieldGroup>
                </FieldSet>

                <DialogFooter>
                    <Button type="button" variant="outline" @click="open = false">Cancelar</Button>
                    <Button type="submit" :disabled="form.processing">
                        {{ form.processing ? 'Guardando...' : 'Registrar' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
