<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { store, update } from '@/routes/inventory';
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
import CheckboxField from '@/components/form/CheckboxField.vue';
import TextField from '@/components/form/TextField.vue';
import type { Medication } from '@/types/inventory';

const props = defineProps<{
    mode: 'create' | 'edit';
    medication?: Medication;
}>();

const open = defineModel<boolean>('open', { default: false });

function defaultValues() {
    return {
        name: props.medication?.name ?? '',
        presentation: props.medication?.presentation ?? '',
        concentration: props.medication?.concentration ?? '',
        dispensing_unit: props.medication?.dispensing_unit ?? '',
        minimum_stock: props.medication?.minimum_stock ?? 0,
        is_active: props.medication?.is_active ?? true,
    };
}

const form = useForm(defaultValues());

/**
 * Refill the form with fresh defaults every time the dialog is opened, so a
 * previous edit/create session never leaks into the next one — matches
 * `RoleFormDialog.vue`'s convention.
 */
watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    form.clearErrors();
    form.defaults(defaultValues());
    form.reset();
});

const title = computed(() => (props.mode === 'create' ? 'Nuevo medicamento' : 'Editar medicamento'));
const description = computed(() =>
    props.mode === 'create'
        ? 'Registra un nuevo medicamento en el catálogo de inventario.'
        : 'Actualiza los datos del medicamento.',
);

const nameErrors = computed(() => form.errors.name);
const presentationErrors = computed(() => form.errors.presentation);
const concentrationErrors = computed(() => form.errors.concentration);
const dispensingUnitErrors = computed(() => form.errors.dispensing_unit);
const minimumStockErrors = computed(() => form.errors.minimum_stock);
const isActiveErrors = computed(() => form.errors.is_active);

function submit() {
    if (props.mode === 'create') {
        form.post(store().url, {
            preserveScroll: true,
            onSuccess: () => {
                open.value = false;
            },
        });
        return;
    }

    if (!props.medication) {
        return;
    }

    form.patch(update(props.medication.uuid).url, {
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
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription>{{ description }}</DialogDescription>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="submit">
                <FieldSet>
                    <FieldGroup class="gap-4">
                        <TextField
                            v-model="form.name"
                            label="Nombre"
                            placeholder="Nombre del medicamento"
                            :errors="nameErrors"
                            hide-required-asterisk
                            maxlength="255"
                            autocomplete="off"
                            required
                        />
                        <TextField
                            v-model="form.presentation"
                            label="Presentación"
                            placeholder="Tableta, jarabe, ampolleta..."
                            :errors="presentationErrors"
                            hide-required-asterisk
                            maxlength="64"
                            autocomplete="off"
                            required
                        />
                        <TextField
                            v-model="form.concentration"
                            label="Concentración"
                            placeholder="500 mg"
                            :errors="concentrationErrors"
                            hide-required-asterisk
                            maxlength="64"
                            autocomplete="off"
                            required
                        />
                        <TextField
                            v-model="form.dispensing_unit"
                            label="Unidad de dispensación"
                            placeholder="Tableta, mililitro..."
                            :errors="dispensingUnitErrors"
                            hide-required-asterisk
                            maxlength="32"
                            autocomplete="off"
                            required
                        />
                        <TextField
                            v-model="form.minimum_stock"
                            label="Existencia mínima"
                            :errors="minimumStockErrors"
                            hide-required-asterisk
                            type="number"
                            min="0"
                            required
                        />
                        <CheckboxField
                            :model-value="form.is_active"
                            label="Activo"
                            :errors="isActiveErrors"
                            @update:model-value="
                                (value: boolean | 'indeterminate') => (form.is_active = value === true)
                            "
                        />
                    </FieldGroup>
                </FieldSet>

                <DialogFooter>
                    <Button type="button" variant="outline" @click="open = false">Cancelar</Button>
                    <Button type="submit" :disabled="form.processing">
                        {{ form.processing ? 'Guardando...' : 'Guardar' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
