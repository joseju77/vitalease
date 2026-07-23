<script setup lang="ts">
import { useDebounceFn } from '@vueuse/core';
import { SearchIcon } from '@lucide/vue';
import { ListboxFilter } from 'reka-ui';
import { onBeforeUnmount, ref, watch } from 'vue';
import { searchPatients as searchPatientsAction } from '@/actions/App/Http/Controllers/DashboardController';
import { Command, CommandItem, CommandList } from '@/components/ui/command';
import type { PatientSearchResult } from '@/types/consultations';

/**
 * Built on the vendored `Command`/`ListboxItem` primitives, but binds
 * `reka-ui`'s raw `ListboxFilter` to a local `query` ref instead of the
 * vendored `CommandInput` (which writes into the shared `filterState.search`
 * and re-filters the already-server-filtered results client-side — see the
 * 04b design decision). Results come entirely from the server; the command
 * context's own filter state is intentionally left untouched.
 */

const MIN_QUERY_LENGTH = 2;
const DEBOUNCE_MS = 300;

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

        results.value = (await response.json()) as PatientSearchResult[];
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

    if (term.length < MIN_QUERY_LENGTH) {
        debouncedSearch.cancel();
        abortController?.abort();
        isLoading.value = false;
        hasSearched.value = false;
        results.value = [];
        return;
    }

    debouncedSearch(term);
});

onBeforeUnmount(() => {
    debouncedSearch.cancel();
    abortController?.abort();
});

function select(patient: PatientSearchResult) {
    emit('select', patient);
    query.value = '';
    results.value = [];
    hasSearched.value = false;
}

const showEmptyState = () => hasSearched.value && !isLoading.value && results.value.length === 0;
</script>

<template>
    <Command class="rounded-lg border shadow-sm">
        <div class="flex items-center border-b px-3">
            <SearchIcon class="mr-2 h-4 w-4 shrink-0 opacity-50" />
            <ListboxFilter
                v-model="query"
                placeholder="Buscar paciente por nombre o número de inscripción..."
                class="flex h-10 w-full rounded-md bg-transparent py-3 text-sm outline-none placeholder:text-muted-foreground"
            />
        </div>
        <CommandList>
            <p v-if="showEmptyState()" class="px-3 py-6 text-center text-sm text-muted-foreground">
                No se encontraron pacientes.
            </p>
            <CommandItem v-for="patient in results" :key="patient.uuid" :value="patient.uuid" @select="select(patient)">
                <div class="flex flex-col">
                    <span class="font-medium">{{ fullName(patient) }}</span>
                    <span v-if="patient.enrollment_number" class="text-xs text-muted-foreground">
                        {{ patient.enrollment_number }}
                    </span>
                </div>
            </CommandItem>
        </CommandList>
    </Command>
</template>
