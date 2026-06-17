<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { store, update } from '@/actions/App/Http/Controllers/UserController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Field, FieldGroup, FieldLabel, FieldSet } from '@/components/ui/field';
import { Switch } from '@/components/ui/switch';
import TextField from '@/components/form/TextField.vue';

interface EditableUser {
    uuid: string;
    name: string;
    email: string;
    has_access: boolean;
}

const props = defineProps<{
    mode: 'create' | 'edit';
    user?: EditableUser;
}>();

const open = defineModel<boolean>('open', { default: false });

const form = useForm({
    name: props.user?.name ?? '',
    email: props.user?.email ?? '',
    password: '',
    has_access: props.user?.has_access ?? true,
});

/**
 * Refill the form with fresh defaults every time the dialog is opened, so a
 * previous edit/create session never leaks into the next one.
 */
watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    form.clearErrors();
    form.defaults({
        name: props.user?.name ?? '',
        email: props.user?.email ?? '',
        password: '',
        has_access: props.user?.has_access ?? true,
    });
    form.reset();
});

const title = computed(() => (props.mode === 'create' ? 'Nuevo usuario' : 'Editar usuario'));
const description = computed(() =>
    props.mode === 'create'
        ? 'Crea una cuenta y define su contraseña inicial.'
        : 'Actualiza los datos y el acceso del usuario.',
);

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

    if (!props.user) {
        return;
    }

    form.transform((data) => ({
        name: data.name,
        email: data.email,
        has_access: data.has_access,
    })).patch(update(props.user.uuid).url, {
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
                            placeholder="Nombre completo"
                            :errors="form.errors.name"
                            hide-required-asterisk
                            maxlength="255"
                            autocomplete="name"
                            required
                        />
                        <TextField
                            v-model="form.email"
                            label="Correo"
                            placeholder="ejemplo@correo.mx"
                            :errors="form.errors.email"
                            hide-required-asterisk
                            type="email"
                            maxlength="255"
                            autocomplete="email"
                            required
                        />
                        <TextField
                            v-if="mode === 'create'"
                            v-model="form.password"
                            label="Contraseña"
                            placeholder="********"
                            :errors="form.errors.password"
                            hide-required-asterisk
                            type="password"
                            maxlength="255"
                            autocomplete="new-password"
                            required
                        />
                        <Field v-else orientation="horizontal">
                            <FieldLabel for="user-has-access">Acceso habilitado</FieldLabel>
                            <Switch id="user-has-access" v-model="form.has_access" />
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
