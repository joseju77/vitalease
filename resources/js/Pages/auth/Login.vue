<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { redirect } from '@/routes/auth/google';
import { create as createPatientRegistration } from '@/actions/App/Http/Controllers/PatientController';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Separator } from '@/components/ui/separator';
import EmailLoginForm from '@/components/auth/EmailLoginForm.vue';
import appLogo from '@resources/img/logos/vitalease.svg';
import googleLogo from '@resources/img/logos/google.svg';

const page = usePage();
const pageError = computed(() => page.props.errors?.email);

const showEmailForm = ref(false);
</script>

<template>
    <Head title="Iniciar Sesión" />

    <div
        class="relative min-h-svh overflow-hidden bg-gradient-to-b from-background via-background to-muted/40 text-foreground"
    >
        <div class="pointer-events-none absolute inset-0">
            <div class="absolute -top-32 -right-24 h-80 w-80 rounded-full bg-secondary/35 blur-sm" />
            <div class="absolute -bottom-32 -left-24 h-96 w-96 rounded-full bg-primary/20 blur-sm" />
            <div class="absolute inset-x-0 top-0 h-40 bg-gradient-to-b from-accent/35 to-transparent" />
        </div>

        <div class="relative z-10 flex min-h-svh items-center justify-center px-6 py-12">
            <Card
                class="relative w-full max-w-md animate-in overflow-hidden border-border/70 bg-card/95 pt-0 shadow-[0_30px_60px_-35px_rgba(35,50,47,0.55)] fade-in-0 slide-in-from-bottom-4"
            >
                <div class="-mb-3 h-1.5 w-full bg-gradient-to-r from-primary via-accent to-secondary" />
                <CardHeader class="items-center justify-items-center gap-3 pt-6 pb-2 text-center">
                    <div
                        class="mx-auto flex size-22 items-center justify-center rounded-2xl bg-secondary/30 ring-1 ring-border/60"
                    >
                        <img :src="appLogo" alt="Logo de VitalEase" class="mx-auto size-14" />
                    </div>
                    <div class="space-y-1">
                        <CardTitle class="text-3xl font-semibold tracking-tight">VitalEase</CardTitle>
                        <CardDescription>Gestión de Salud Integral</CardDescription>
                    </div>
                </CardHeader>

                <CardContent class="space-y-3">
                    <Alert v-if="pageError && !showEmailForm" variant="destructive">
                        <AlertDescription>{{ pageError }}</AlertDescription>
                    </Alert>

                    <div class="space-y-3">
                        <Button
                            as-child
                            size="lg"
                            variant="outline"
                            class="w-full border-border/70 bg-background/70 text-foreground hover:bg-accent/50"
                        >
                            <a :href="redirect().url" class="inline-flex items-center justify-center gap-2">
                                <img :src="googleLogo" alt="Google Logo" class="size-5" />
                                <span>Iniciar sesión con Google</span>
                            </a>
                        </Button>
                        <div class="flex items-center gap-3">
                            <Separator class="flex-1" />
                            <span class="text-xs tracking-[0.3em] text-muted-foreground uppercase">o</span>
                            <Separator class="flex-1" />
                        </div>
                        <Button v-if="!showEmailForm" size="lg" class="w-full" @click="showEmailForm = true">
                            Iniciar sesión con correo y contraseña
                        </Button>
                        <p v-else class="text-center text-sm text-muted-foreground">
                            Ingresa tus credenciales para continuar.
                        </p>
                    </div>

                    <Transition
                        enter-active-class="transition duration-300 ease-out"
                        enter-from-class="translate-y-2 opacity-0"
                        enter-to-class="translate-y-0 opacity-100"
                        leave-active-class="transition duration-200 ease-in"
                        leave-from-class="translate-y-0 opacity-100"
                        leave-to-class="-translate-y-2 opacity-0"
                    >
                        <EmailLoginForm v-if="showEmailForm" />
                    </Transition>

                    <p class="pt-2 text-center text-sm text-muted-foreground">
                        ¿Eres paciente?
                        <a :href="createPatientRegistration().url" class="font-medium text-primary hover:underline">
                            Regístrate aquí
                        </a>
                    </p>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
