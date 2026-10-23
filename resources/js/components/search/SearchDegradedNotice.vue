<script setup lang="ts">
import { Info } from '@lucide/vue';
</script>

<template>
    <p role="status" data-testid="search-degraded-notice" class="flex items-start gap-2 text-sm text-muted-foreground">
        <Info class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
        <span>
            El buscador avanzado no está disponible por el momento. Se muestran resultados básicos: la búsqueda no
            corrige errores de escritura ni distingue acentos.
        </span>
    </p>
</template>
