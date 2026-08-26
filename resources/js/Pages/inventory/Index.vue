<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import MedicationActionDialog from '@/components/inventory/MedicationActionDialog.vue';
import MedicationFormDialog from '@/components/inventory/MedicationFormDialog.vue';
import { useMedicationFlashToast } from '@/composables/useMedicationFlashToast';
import { usePermissions } from '@/composables/usePermissions';
import { activate as activateMedication, index as inventoryIndex, show as inventoryShow } from '@/routes/inventory';
import type { InventoryFilters, Medication, Paginator } from '@/types/inventory';

defineOptions({
    layout: (h: any, page: any) => h(AppLayout, { title: 'Inventario' }, () => page),
});

const props = defineProps<{
    medications: Paginator<Medication>;
    filters: InventoryFilters;
}>();

useMedicationFlashToast();

const { can } = usePermissions();

const search = ref(props.filters.q ?? '');
const lowStockOnly = ref(props.filters.low_stock);

const createOpen = ref(false);
const editingMedication = ref<Medication | null>(null);

const editOpen = computed({
    get: () => editingMedication.value !== null,
    set: (value: boolean) => {
        if (!value) {
            editingMedication.value = null;
        }
    },
});

function edit(medication: Medication) {
    editingMedication.value = medication;
}

const actionMedication = ref<Medication | null>(null);
const actionType = ref<'deactivate' | 'delete'>('deactivate');

const actionOpen = computed({
    get: () => actionMedication.value !== null,
    set: (value: boolean) => {
        if (!value) {
            actionMedication.value = null;
        }
    },
});

/** Activation is reversible and needs no confirmation, unlike deactivate/delete. */
function activate(medication: Medication) {
    router.patch(activateMedication(medication.uuid).url, {}, { preserveScroll: true });
}

function confirmDeactivate(medication: Medication) {
    actionType.value = 'deactivate';
    actionMedication.value = medication;
}

function confirmDelete(medication: Medication) {
    actionType.value = 'delete';
    actionMedication.value = medication;
}

function applyFilters() {
    const query: Record<string, string | boolean> = {};

    const term = search.value.trim();
    if (term !== '') {
        query.q = term;
    }

    if (lowStockOnly.value) {
        query.low_stock = true;
    }

    router.get(inventoryIndex().url, query, {
        preserveState: true,
        replace: true,
        preserveScroll: true,
    });
}

const debouncedApplyFilters = useDebounceFn(applyFilters, 300);

watch([search, lowStockOnly], () => {
    debouncedApplyFilters();
});

function toggleLowStock(value: boolean | 'indeterminate') {
    lowStockOnly.value = value === true;
}

onBeforeUnmount(() => {
    debouncedApplyFilters.cancel();
});
</script>

<template>
    <Head title="Inventario" />

    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-semibold">Inventario</h1>
            <div class="flex items-center gap-2">
                <Button v-if="can('inventory.create')" @click="createOpen = true">Nuevo medicamento</Button>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-4">
            <Input
                v-model="search"
                type="search"
                placeholder="Buscar por nombre, presentación o concentración..."
                class="max-w-sm"
            />
            <Field orientation="horizontal" class="w-auto">
                <Checkbox
                    id="inventory-low-stock-filter"
                    :model-value="lowStockOnly"
                    @update:model-value="toggleLowStock"
                />
                <FieldLabel for="inventory-low-stock-filter">Solo stock bajo</FieldLabel>
            </Field>
        </div>

        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>Nombre</TableHead>
                    <TableHead>Presentación</TableHead>
                    <TableHead>Concentración</TableHead>
                    <TableHead>Unidad de dispensación</TableHead>
                    <TableHead>Stock</TableHead>
                    <TableHead>Estado</TableHead>
                    <TableHead class="text-right">Acciones</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                <TableEmpty v-if="medications.data.length === 0" :colspan="7">
                    No se encontraron medicamentos.
                </TableEmpty>
                <TableRow v-for="medication in medications.data" :key="medication.uuid">
                    <TableCell>
                        <Link :href="inventoryShow(medication.uuid).url" class="font-medium hover:underline">
                            {{ medication.name }}
                        </Link>
                    </TableCell>
                    <TableCell>{{ medication.presentation }}</TableCell>
                    <TableCell>{{ medication.concentration }}</TableCell>
                    <TableCell>{{ medication.dispensing_unit }}</TableCell>
                    <TableCell>
                        <div class="flex items-center gap-2">
                            <span>{{ medication.current_stock }} / {{ medication.minimum_stock }}</span>
                            <Badge v-if="medication.is_low_stock" variant="destructive">Stock bajo</Badge>
                        </div>
                    </TableCell>
                    <TableCell>
                        <Badge v-if="!medication.is_active" variant="secondary">Inactivo</Badge>
                    </TableCell>
                    <TableCell class="text-right">
                        <div class="flex justify-end gap-2">
                            <Button
                                v-if="can('inventory.update')"
                                variant="outline"
                                size="sm"
                                @click="edit(medication)"
                            >
                                Editar
                            </Button>
                            <Button
                                v-if="can('inventory.update') && !medication.is_active"
                                variant="outline"
                                size="sm"
                                @click="activate(medication)"
                            >
                                Activar
                            </Button>
                            <Button
                                v-if="can('inventory.update') && medication.is_active"
                                variant="outline"
                                size="sm"
                                @click="confirmDeactivate(medication)"
                            >
                                Desactivar
                            </Button>
                            <Button
                                v-if="can('inventory.delete') && medication.can_be_deleted"
                                variant="destructive"
                                size="sm"
                                @click="confirmDelete(medication)"
                            >
                                Eliminar
                            </Button>
                        </div>
                    </TableCell>
                </TableRow>
            </TableBody>
        </Table>

        <nav v-if="medications.links.length > 3" class="flex flex-wrap items-center gap-1">
            <template v-for="link in medications.links" :key="link.label">
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

    <MedicationFormDialog v-if="can('inventory.create')" v-model:open="createOpen" mode="create" />
    <MedicationFormDialog
        v-if="editingMedication"
        :key="editingMedication.uuid"
        v-model:open="editOpen"
        mode="edit"
        :medication="editingMedication"
    />
    <MedicationActionDialog
        v-if="actionMedication"
        :key="`${actionType}-${actionMedication.uuid}`"
        v-model:open="actionOpen"
        :medication="actionMedication"
        :action="actionType"
    />
</template>
