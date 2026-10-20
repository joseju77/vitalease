<script setup lang="ts">
import { History } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { history as historyAction } from '@/actions/App/Http/Controllers/MedicalConsultationController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { formatDateTime } from '@/lib/formatDateTime';
import type {
    ConsultationHistoryChange,
    ConsultationHistoryGroup,
    ConsultationHistoryPage,
} from '@/types/consultations';

const props = defineProps<{ uuid: string; code: string }>();

const open = ref(false);
const groups = ref<ConsultationHistoryGroup[]>([]);
const nextCursor = ref<number | null>(null);
const isLoading = ref(false);
const isLoadingMore = ref(false);
const hasFailed = ref(false);
let abortController: AbortController | null = null;

const EVENT_VARIANTS: Record<ConsultationHistoryGroup['event'], 'default' | 'secondary' | 'destructive' | 'outline'> = {
    created: 'default',
    updated: 'secondary',
    voided: 'destructive',
    restored: 'outline',
};

async function fetchPage(cursor: number | null) {
    abortController?.abort();
    const controller = new AbortController();
    abortController = controller;

    const isFirstPage = cursor === null;
    isLoading.value = isFirstPage;
    isLoadingMore.value = !isFirstPage;
    hasFailed.value = false;

    if (isFirstPage) {
        groups.value = [];
        nextCursor.value = null;
    }

    try {
        const url = historyAction.url(props.uuid, isFirstPage ? undefined : { query: { cursor } });
        const response = await fetch(url, { headers: { Accept: 'application/json' }, signal: controller.signal });

        if (controller.signal.aborted) {
            return;
        }

        if (!response.ok) {
            hasFailed.value = true;
            return;
        }

        const page = (await response.json()) as ConsultationHistoryPage;
        groups.value = [...groups.value, ...page.data];
        nextCursor.value = page.next_cursor;
    } catch {
        if (controller.signal.aborted) {
            return;
        }

        hasFailed.value = true;
    } finally {
        if (!controller.signal.aborted) {
            isLoading.value = false;
            isLoadingMore.value = false;
        }
    }
}

watch(open, (isOpen) => {
    if (isOpen) {
        fetchPage(null);
        return;
    }

    abortController?.abort();
});

/** Groups a flat change list by section, keeping the server order. */
function sectionsOf(changes: ConsultationHistoryChange[]): { name: string; changes: ConsultationHistoryChange[] }[] {
    const sections = new Map<string, ConsultationHistoryChange[]>();

    for (const change of changes) {
        sections.set(change.section, [...(sections.get(change.section) ?? []), change]);
    }

    return [...sections].map(([name, sectionChanges]) => ({ name, changes: sectionChanges }));
}

function displayValue(value: ConsultationHistoryChange['old']): string {
    if (value === null || value === '') {
        return '—';
    }

    if (typeof value === 'boolean') {
        return value ? 'Sí' : 'No';
    }

    return String(value);
}

function fieldLabel(change: ConsultationHistoryChange): string {
    return change.item ? `${change.item} · ${change.field}` : change.field;
}

const isEmpty = computed(() => !isLoading.value && !hasFailed.value && groups.value.length === 0);
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button type="button" variant="outline"><History aria-hidden="true" />Historial de cambios</Button>
        </DialogTrigger>
        <DialogContent class="max-h-[85vh] overflow-y-auto sm:max-w-3xl">
            <DialogHeader>
                <DialogTitle>Historial de cambios</DialogTitle>
                <DialogDescription>Registro de cambios de la consulta {{ code }}.</DialogDescription>
            </DialogHeader>

            <div v-if="isLoading" class="space-y-2" data-testid="history-loading">
                <Skeleton class="h-4 w-3/4" />
                <Skeleton class="h-4 w-1/2" />
                <Skeleton class="h-4 w-2/3" />
            </div>

            <p v-else-if="hasFailed && groups.length === 0" class="text-sm text-muted-foreground">
                No se pudo cargar el historial de cambios.
            </p>

            <p v-else-if="isEmpty" class="text-sm text-muted-foreground">Esta consulta no tiene cambios registrados.</p>

            <ol v-else class="space-y-4">
                <li v-for="group in groups" :key="group.id" class="space-y-2 rounded-md border p-3">
                    <div class="flex flex-wrap items-center gap-2 text-sm">
                        <Badge :variant="EVENT_VARIANTS[group.event]">{{ group.event_label }}</Badge>
                        <span class="text-muted-foreground">{{ formatDateTime(group.occurred_at) }}</span>
                        <span v-if="group.actor" class="font-medium">{{ group.actor.name }}</span>
                    </div>
                    <p v-if="group.reason" class="text-sm">Motivo: {{ group.reason }}</p>
                    <section v-for="section in sectionsOf(group.changes)" :key="section.name" class="space-y-1">
                        <h3 class="text-sm font-semibold capitalize">{{ section.name }}</h3>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Campo</TableHead>
                                    <TableHead>Anterior</TableHead>
                                    <TableHead>Nuevo</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow v-for="(change, index) in section.changes" :key="index">
                                    <TableCell>{{ fieldLabel(change) }}</TableCell>
                                    <TableCell class="whitespace-pre-wrap text-muted-foreground">{{
                                        displayValue(change.old)
                                    }}</TableCell>
                                    <TableCell class="whitespace-pre-wrap">{{ displayValue(change.new) }}</TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </section>
                </li>
            </ol>

            <p v-if="hasFailed && groups.length > 0" class="text-sm text-destructive">
                No se pudieron cargar más cambios.
            </p>

            <Button
                v-if="nextCursor !== null && !isLoading"
                type="button"
                variant="outline"
                :disabled="isLoadingMore"
                @click="fetchPage(nextCursor)"
            >
                {{ isLoadingMore ? 'Cargando...' : 'Cargar más' }}
            </Button>
        </DialogContent>
    </Dialog>
</template>
