<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { store as storeAdjustment } from '@/routes/inventory/adjustments';
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
import SelectField from '@/components/form/SelectField.vue';
import TextField from '@/components/form/TextField.vue';
import TextareaField from '@/components/form/TextareaField.vue';
import type { Medication } from '@/types/inventory';

const props = defineProps<{
    medication: Medication;
}>();

const open = defineModel<boolean>('open', { default: false });

const directionOptions = [
    { value: 'increase', label: 'Aumentar' },
    { value: 'decrease', label: 'Disminuir' },
];

function defaultValues() {
    return {
        direction: 'increase' as 'increase' | 'decrease',
        magnitude: 1,
        notes: '',
    };
}

const form = useForm(defaultValues());

/**
 * Refill the form with fresh defaults every time the dialog is opened, so a
 * previous adjustment never leaks into the next one — matches
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

/**
 * `RecordAdjustmentRequest` only accepts `quantity` (signed) and `notes`, so
 * every server-side quantity error — sign, magnitude, or non-zero — surfaces
 * on the `quantity` key even though the user only edits a positive
 * `magnitude` plus a `direction`.
 */
const magnitudeErrors = computed(() => form.errors.quantity);
const notesErrors = computed(() => form.errors.notes);

function submit() {
    form.transform((data) => ({
        quantity: data.direction === 'decrease' ? -Math.abs(data.magnitude) : Math.abs(data.magnitude),
        notes: data.notes,
    })).post(storeAdjustment(props.medication.uuid).url, {
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
                <DialogTitle>Registrar ajuste</DialogTitle>
                <DialogDescription>
                    Corrige la existencia de {{ medication.name }}. Existencia actual: {{ medication.current_stock }}.
                </DialogDescription>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="submit">
                <FieldSet>
                    <FieldGroup class="gap-4">
                        <SelectField
                            v-model="form.direction"
                            label="Tipo de ajuste"
                            :options="directionOptions"
                            native-select
                            hide-required-asterisk
                        />
                        <TextField
                            v-model="form.magnitude"
                            label="Cantidad"
                            :errors="magnitudeErrors"
                            hide-required-asterisk
                            type="number"
                            min="1"
                            required
                        />
                        <TextareaField
                            id="stock-adjustment-notes"
                            v-model="form.notes"
                            label="Notas"
                            :errors="notesErrors"
                            required
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
