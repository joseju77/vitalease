<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { store } from '@/routes/auth/login';
import { Button } from '@/components/ui/button';
import { FieldGroup, FieldSet } from '@/components/ui/field';
import TextField from '@/components/form/TextField.vue';
</script>

<template>
    <Form
        v-slot="{ errors, processing }"
        :action="store()"
        method="post"
        :options="{ only: ['errors'] }"
        class="space-y-4"
    >
        <FieldSet>
            <FieldGroup class="gap-4">
                <TextField
                    label="Correo"
                    placeholder="ejemplo@correo.mx"
                    :errors="errors.email"
                    hide-required-asterisk
                    name="email"
                    maxlength="255"
                    type="email"
                    autocomplete="email"
                    required
                />
                <TextField
                    label="Contraseña"
                    placeholder="********"
                    :errors="errors.password"
                    hide-required-asterisk
                    type="password"
                    name="password"
                    maxlength="255"
                    autocomplete="current-password"
                    required
                />
            </FieldGroup>
        </FieldSet>

        <Button type="submit" size="lg" class="w-full" :disabled="processing">
            {{ processing ? 'Iniciando sesión...' : 'Iniciar sesión' }}
        </Button>
    </Form>
</template>
