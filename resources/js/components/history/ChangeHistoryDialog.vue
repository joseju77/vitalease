<script setup lang="ts">
import { History } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
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
import type { HistoryChange, HistoryGroup, HistoryPage } from '@/types/history';

/**
 * Generic change-history dialog shared by every audited subject. The subject
 * only supplies how to build the endpoint URL for a cursor, plus its copy.
 */
const props = defineProps<{
    /** Builds the history endpoint URL; `cursor` is null for the first page. */
    buildUrl: (cursor: number | null) => string;
    description: string;
    emptyMessage: string;
    triggerTestId?: string;
}>();

const open = ref(false);
const groups = ref<HistoryGroup[]>([]);
const nextCursor = ref<number | null>(null);
const isLoading = ref(false);
const isLoadingMore = ref(false);
const hasFailed = ref(false);
let abortController: AbortController | null = null;

const EVENT_VARIANTS: Record<HistoryGroup['event'], 'default' | 'secondary' | 'destructive' | 'outline'> = {
    created: 'default',
    updated: 'secondary',
    voided: 'destructive',
    restored: 'outline',
    disabled: 'destructive',
    enabled: 'outline',
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
        const url = props.buildUrl(cursor);
        const response = await fetch(url, { headers: { Accept: 'application/json' }, signal: controller.signal });

        if (controller.signal.aborted) {
            return;
        }

        if (!response.ok) {
            hasFailed.value = true;
            return;
        }

        const page = (await response.json()) as HistoryPage;
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
function sectionsOf(changes: HistoryChange[]): { name: string; changes: HistoryChange[] }[] {
    const sections = new Map<string, HistoryChange[]>();

    for (const change of changes) {
        sections.set(change.section, [...(sections.get(change.section) ?? []), change]);
    }

    return [...sections].map(([name, sectionChanges]) => ({ name, changes: sectionChanges }));
}

function displayValue(value: HistoryChange['old']): string {
    if (value === null || value === '') {
        return '—';
    }

    if (typeof value === 'boolean') {
        return value ? 'Sí' : 'No';
    }

    return String(value);
}

function fieldLabel(change: HistoryChange): string {
    return change.item ? `${change.item} · ${change.field}` : change.field;
}

const isEmpty = computed(() => !isLoading.value && !hasFailed.value && groups.value.length === 0);
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button type="button" variant="outline" :data-testid="triggerTestId"
                ><History aria-hidden="true" />Historial de cambios</Button
            >
        </DialogTrigger>
        <DialogContent class="max-h-[85vh] overflow-y-auto sm:max-w-3xl">
            <DialogHeader>
                <DialogTitle>Historial de cambios</DialogTitle>
                <DialogDescription>{{ description }}</DialogDescription>
            </DialogHeader>

            <div v-if="isLoading" class="space-y-2" data-testid="history-loading">
                <Skeleton class="h-4 w-3/4" />
                <Skeleton class="h-4 w-1/2" />
                <Skeleton class="h-4 w-2/3" />
            </div>

            <p v-else-if="hasFailed && groups.length === 0" class="text-sm text-muted-foreground">
                No se pudo cargar el historial de cambios.
            </p>

            <p v-else-if="isEmpty" class="text-sm text-muted-foreground">{{ emptyMessage }}</p>

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
