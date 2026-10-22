<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { usePermissions } from '@/composables/usePermissions';
import { show as patientShow } from '@/routes/patients';
import type { ConsultationPatient } from '@/types/consultations';

defineProps<{ title: string; patient: ConsultationPatient }>();

const { can } = usePermissions();
</script>

<template>
    <header
        class="sticky top-0 z-20 -mx-6 -mt-6 flex flex-wrap items-center justify-between gap-3 border-b border-border/70 bg-background/95 px-6 py-4 backdrop-blur supports-[backdrop-filter]:bg-background/80"
    >
        <div class="min-w-0 space-y-1">
            <h1 class="text-2xl font-semibold">{{ title }}</h1>
            <p class="truncate text-sm text-muted-foreground">
                <Link
                    v-if="can('patients.view')"
                    :href="patientShow(patient.uuid).url"
                    class="font-medium text-foreground hover:underline"
                >
                    {{ patient.full_name }}
                </Link>
                <span v-else class="font-medium text-foreground">{{ patient.full_name }}</span>
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
