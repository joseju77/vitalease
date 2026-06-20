<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { toast } from 'vue-sonner';
import { store, update } from '@/actions/App/Http/Controllers/RoleController';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Field, FieldGroup, FieldLabel, FieldLegend, FieldSet } from '@/components/ui/field';
import TextField from '@/components/form/TextField.vue';
import { groupPermissionsByResource, permissionActionLabel } from '@/lib/permissionLabels';

interface EditableRole {
    id: number;
    name: string;
    permissions: string[];
}

const props = defineProps<{
    mode: 'create' | 'edit';
    permissionCatalog: string[];
    role?: EditableRole;
}>();

const open = defineModel<boolean>('open', { default: false });

const form = useForm({
    name: props.role?.name ?? '',
    permissions: [...(props.role?.permissions ?? [])],
});

/**
 * Refill the form with fresh defaults every time the dialog is opened, so a
 * previous edit/create session never leaks into the next one — matches
 * `UserFormDialog.vue`'s convention.
 */
watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    form.clearErrors();
    form.defaults({
        name: props.role?.name ?? '',
        permissions: [...(props.role?.permissions ?? [])],
    });
    form.reset();
});

const title = computed(() => (props.mode === 'create' ? 'Nuevo rol' : 'Editar rol'));
const description = computed(() =>
    props.mode === 'create' ? 'Crea un rol y asigna sus permisos.' : 'Actualiza el nombre y los permisos del rol.',
);

/**
 * Groups the permission catalog by resource prefix, identical logic to
 * `UserAuthorizationDialog.vue`'s `permissionGroups`.
 */
const permissionGroups = computed(() => groupPermissionsByResource(props.permissionCatalog));

const permissionLabel = permissionActionLabel;

function isPermissionChecked(permission: string): boolean {
    return form.permissions.includes(permission);
}

function togglePermission(permission: string, checked: boolean) {
    if (checked) {
        if (!form.permissions.includes(permission)) {
            form.permissions = [...form.permissions, permission];
        }
        return;
    }

    form.permissions = form.permissions.filter((current) => current !== permission);
}

function submit() {
    if (props.mode === 'create') {
        form.post(store().url, {
            preserveScroll: true,
            onSuccess: () => {
                open.value = false;
                toast.success('Rol creado');
            },
        });
        return;
    }

    if (!props.role) {
        return;
    }

    form.patch(update(props.role.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            toast.success('Rol actualizado');
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-lg">
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
                            placeholder="Nombre del rol"
                            :errors="form.errors.name"
                            hide-required-asterisk
                            maxlength="255"
                            autocomplete="off"
                            required
                        />
                    </FieldGroup>
                </FieldSet>

                <FieldSet v-for="group in permissionGroups" :key="group.resource">
                    <FieldLegend variant="label">{{ group.label }}</FieldLegend>
                    <FieldGroup class="grid grid-cols-2 gap-3">
                        <Field v-for="permission in group.permissions" :key="permission" orientation="horizontal">
                            <Checkbox
                                :id="`role-permission-${permission}`"
                                :model-value="isPermissionChecked(permission)"
                                @update:model-value="(value) => togglePermission(permission, value === true)"
                            />
                            <FieldLabel :for="`role-permission-${permission}`">
                                {{ permissionLabel(permission) }}
                            </FieldLabel>
                        </Field>
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
