<script setup lang="ts">
import { useDebounceFn } from '@vueuse/core';
import { Search } from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { searchPatients as searchPatientsAction } from '@/actions/App/Http/Controllers/DashboardController';
import SearchResultsField from '@/components/form/SearchResultsField.vue';
import type { PatientSearchResult } from '@/types/consultations';

const MIN_QUERY_LENGTH = 1;
const DEBOUNCE_MS = 50;

const emit = defineEmits<{
    select: [patient: PatientSearchResult];
}>();

const query = ref('');
const results = ref<PatientSearchResult[]>([]);
const isLoading = ref(false);
const hasSearched = ref(false);
let abortController: AbortController | null = null;

function fullName(patient: PatientSearchResult): string {
    return [patient.first_name, patient.last_name, patient.second_last_name].filter(Boolean).join(' ');
}

const options = computed(() =>
    results.value.map((patient) => ({
        value: patient.uuid,
        label: fullName(patient),
        description: patient.enrollment_number,
    })),
);

async function runSearch(term: string) {
    abortController?.abort();
    const controller = new AbortController();
    abortController = controller;

    isLoading.value = true;

    try {
        const response = await fetch(searchPatientsAction.url({ query: { query: term } }), {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        });

        if (controller.signal.aborted) {
            return;
        }

        if (!response.ok) {
            results.value = [];
            return;
        }

        const matches = (await response.json()) as PatientSearchResult[];
        if (controller.signal.aborted) {
            return;
        }
        results.value = matches;
    } catch {
        if (controller.signal.aborted) {
            return;
        }

        results.value = [];
    } finally {
        if (!controller.signal.aborted) {
            isLoading.value = false;
            hasSearched.value = true;
        }
    }
}

const debouncedSearch = useDebounceFn(runSearch, DEBOUNCE_MS);

watch(query, (value) => {
    const term = value.trim();

    debouncedSearch.cancel();
    abortController?.abort();
    isLoading.value = false;
    hasSearched.value = false;
    results.value = [];

    if (term.length < MIN_QUERY_LENGTH) {
        return;
    }

    debouncedSearch(term);
});

onBeforeUnmount(() => {
    debouncedSearch.cancel();
    abortController?.abort();
});

function select(value: string) {
    const patient = results.value.find((result) => result.uuid === value);
    if (!patient) return;

    emit('select', patient);
    query.value = '';
    results.value = [];
    hasSearched.value = false;
}
</script>

<template>
    <SearchResultsField
        v-model="query"
        label="Paciente"
        placeholder="Buscar paciente por nombre o número de inscripción..."
        empty-message="No se encontraron pacientes."
        loading-message="Buscando pacientes..."
        :options="options"
        :loading="isLoading"
        :searched="hasSearched"
        :min-query-length="MIN_QUERY_LENGTH"
        input-class="h-10 bg-background shadow-xs dark:bg-input/30"
        @select="select"
    >
        <template #leading>
            <Search />
        </template>
    </SearchResultsField>
</template>
