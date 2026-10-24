<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';

/**
 * Rendered for a 503 when PostgreSQL or Redis is unreachable. Deliberately
 * standalone: no layout and no shared props, since both depend on the session
 * and the database that may be the cause of the outage.
 */
function reload(): void {
    window.location.reload();
}
</script>

<template>
    <Head title="Servicio no disponible" />

    <main class="flex min-h-svh items-center justify-center bg-background px-6 py-12 text-foreground">
        <div class="max-w-md space-y-4 text-center">
            <h1 class="text-2xl font-semibold">Servicio temporalmente no disponible</h1>
            <p class="text-muted-foreground">
                No pudimos completar la solicitud porque un componente del sistema no responde. Tus datos no se
                modificaron. Intenta de nuevo en unos momentos.
            </p>
            <Button type="button" @click="reload">Reintentar</Button>
        </div>
    </main>
</template>
