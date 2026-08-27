<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { formatDateTime } from '@/lib/formatDateTime';
import { movementTypeLabel } from '@/lib/inventoryLabels';
import type { InventoryMovement, Paginator } from '@/types/inventory';

/**
 * Paginated movement ledger for one medication, most recent first. Shared
 * shape with `Pages/inventory/Index.vue`'s inline pagination: `medications`
 * there, `movements` here.
 */
defineProps<{
    movements: Paginator<InventoryMovement>;
}>();

const EMPTY_VALUE = '—';

/** "+10" for an entry, "-3" for a dispensation/adjustment that reduced stock. */
function formatSignedQuantity(quantity: number): string {
    return quantity > 0 ? `+${quantity}` : `${quantity}`;
}
</script>

<template>
    <div class="space-y-4">
        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>Fecha</TableHead>
                    <TableHead>Tipo</TableHead>
                    <TableHead>Cantidad</TableHead>
                    <TableHead>Existencia</TableHead>
                    <TableHead>Consulta</TableHead>
                    <TableHead>Usuario</TableHead>
                    <TableHead>Notas</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                <TableEmpty v-if="movements.data.length === 0" :colspan="7">
                    No se encontraron movimientos.
                </TableEmpty>
                <TableRow v-for="movement in movements.data" :key="movement.id">
                    <TableCell>{{ formatDateTime(movement.occurred_at) }}</TableCell>
                    <TableCell>
                        <Badge variant="secondary">{{ movementTypeLabel(movement.type) }}</Badge>
                    </TableCell>
                    <TableCell>{{ formatSignedQuantity(movement.quantity) }}</TableCell>
                    <TableCell>{{ movement.stock_after }}</TableCell>
                    <TableCell>{{ movement.medical_consultation_code ?? EMPTY_VALUE }}</TableCell>
                    <TableCell>{{ movement.user ?? EMPTY_VALUE }}</TableCell>
                    <TableCell>{{ movement.notes ?? EMPTY_VALUE }}</TableCell>
                </TableRow>
            </TableBody>
        </Table>

        <nav v-if="movements.links.length > 3" class="flex flex-wrap items-center gap-1">
            <template v-for="link in movements.links" :key="link.label">
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
