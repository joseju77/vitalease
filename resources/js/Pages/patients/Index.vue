<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { onBeforeUnmount, ref, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import SearchDegradedNotice from '@/components/search/SearchDegradedNotice.vue';
import { Input } from '@/components/ui/input';
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select';
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/AppLayout.vue';
import { index as patientsIndex, show as patientShow } from '@/routes/patients';
import type { AppPageProps } from '@/types';
import type { Paginator } from '@/types/inventory';
import type { PatientIndexFilters, PatientIndexRow, PatientStatusFilter } from '@/types/patients';

defineOptions({
    layout: (h: any, page: any) => h(AppLayout, { title: 'Pacientes' }, () => page),
});

const props = defineProps<{
    patients: Paginator<PatientIndexRow>;
    filters: PatientIndexFilters;
}>();

const page = usePage<AppPageProps>();

const STATUS_OPTIONS: { value: PatientStatusFilter; label: string }[] = [
    { value: 'active', label: 'Activos' },
    { value: 'disabled', label: 'Deshabilitados' },
    { value: 'all', label: 'Todos' },
];

const search = ref(props.filters.q ?? '');
const status = ref<PatientStatusFilter>(props.filters.status);

function applyFilters() {
    const query: Record<string, string> = {};

    const term = search.value.trim();
    if (term !== '') {
        query.q = term;
    }

    if (status.value !== 'active') {
        query.status = status.value;
    }

    router.get(patientsIndex().url, query, {
        preserveState: true,
        replace: true,
        preserveScroll: true,
    });
}

const debouncedApplyFilters = useDebounceFn(applyFilters, 300);

watch([search, status], () => {
    debouncedApplyFilters();
});

onBeforeUnmount(() => {
    debouncedApplyFilters.cancel();
});
</script>

<template>
    <Head title="Pacientes" />

    <div class="space-y-6">
        <h1 class="text-2xl font-semibold">Pacientes</h1>

        <div class="flex flex-wrap items-center gap-4">
            <Input
                v-model="search"
                type="search"
                aria-label="Buscar pacientes"
                placeholder="Buscar por nombre o número de inscripción..."
                class="max-w-sm"
            />
            <NativeSelect v-model="status" aria-label="Estado del paciente">
                <NativeSelectOption v-for="option in STATUS_OPTIONS" :key="option.value" :value="option.value">
                    {{ option.label }}
                </NativeSelectOption>
            </NativeSelect>
        </div>

        <SearchDegradedNotice v-if="page.props?.search_degraded" />

        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>Nombre</TableHead>
                    <TableHead>Inscripción</TableHead>
                    <TableHead>Número de inscripción</TableHead>
                    <TableHead>Estado</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                <TableEmpty v-if="patients.data.length === 0" :colspan="4">No se encontraron pacientes.</TableEmpty>
                <TableRow v-for="patient in patients.data" :key="patient.uuid">
                    <TableCell>
                        <Link :href="patientShow(patient.uuid).url" class="font-medium hover:underline">
                            {{ patient.full_name }}
                        </Link>
                    </TableCell>
                    <TableCell>{{ patient.enrollment ?? '—' }}</TableCell>
                    <TableCell>{{ patient.enrollment_number ?? '—' }}</TableCell>
                    <TableCell>
                        <Badge v-if="patient.is_disabled" variant="secondary">Deshabilitado</Badge>
                    </TableCell>
                </TableRow>
            </TableBody>
        </Table>

        <nav v-if="patients.links.length > 3" class="flex flex-wrap items-center gap-1">
            <template v-for="link in patients.links" :key="link.label">
                <span
                    v-if="!link.url"
                    class="rounded-md px-3 py-1.5 text-sm text-muted-foreground/50"
                    v-html="link.label"
                />
                <Link
                    v-else
                    :href="link.url"
                    preserve-scroll
                    class="rounded-md px-3 py-1.5 text-sm hover:bg-muted"
                    :class="{ 'bg-muted font-medium': link.active }"
                >
                    <span v-html="link.label" />
                </Link>
            </template>
        </nav>
    </div>
</template>
