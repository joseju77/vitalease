<script setup lang="ts">
import type { ConsultationPatient } from '@/types/consultations';

defineProps<{ title: string; patient: ConsultationPatient }>();
</script>

<template>
    <header
        class="sticky top-0 z-20 -mx-6 -mt-6 flex flex-wrap items-center justify-between gap-3 border-b border-border/70 bg-background/95 px-6 py-4 backdrop-blur supports-[backdrop-filter]:bg-background/80"
    >
        <div class="min-w-0 space-y-1">
            <h1 class="text-2xl font-semibold">{{ title }}</h1>
            <p class="truncate text-sm text-muted-foreground">
                <span class="font-medium text-foreground">{{ patient.full_name }}</span>
                <span aria-hidden="true"> · </span>
                <span>{{ patient.enrollment_number ? `#${patient.enrollment_number}` : 'Sin inscripción' }}</span>
            </p>
            <slot name="meta" />
        </div>
        <div v-if="$slots.actions" class="flex shrink-0 items-center gap-2">
            <slot name="actions" />
        </div>
    </header>
</template>
