<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import MedicationActionDialog from '@/components/inventory/MedicationActionDialog.vue';
import MedicationFormDialog from '@/components/inventory/MedicationFormDialog.vue';
import MovementHistoryTable from '@/components/inventory/MovementHistoryTable.vue';
import { useMedicationFlashToast } from '@/composables/useMedicationFlashToast';
import { usePermissions } from '@/composables/usePermissions';
import { activate as activateMedication, index as inventoryIndex } from '@/routes/inventory';
import type { InventoryMovement, Medication, Paginator } from '@/types/inventory';

/**
 * The breadcrumb title stays generic, matching every other detail page
 * (`Pages/consultations/Show.vue`); the browser tab title below is the
 * medication name itself.
 */
defineOptions({
    layout: (h: any, page: any) => h(AppLayout, { title: 'Detalle de medicamento' }, () => page),
});

const props = defineProps<{
    medication: Medication;
    movements: Paginator<InventoryMovement>;
}>();

useMedicationFlashToast();

const { can } = usePermissions();

const editOpen = ref(false);

const actionType = ref<'deactivate' | 'delete'>('deactivate');
const actionOpen = ref(false);

/** Activation is reversible and needs no confirmation, unlike deactivate/delete. */
function activate() {
    router.patch(activateMedication(props.medication.uuid).url, {}, { preserveScroll: true });
}

function confirmDeactivate() {
    actionType.value = 'deactivate';
    actionOpen.value = true;
}

function confirmDelete() {
    actionType.value = 'delete';
    actionOpen.value = true;
}
</script>

<template>
    <Head :title="medication.name" />

    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="space-y-1">
                <Link :href="inventoryIndex().url" class="text-sm text-muted-foreground hover:underline">
                    &laquo; Volver al inventario
                </Link>
                <h1 class="text-2xl font-semibold">{{ medication.name }}</h1>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <!--
                    WU6 adds "Registrar entrada" (gated inventory.create) and
                    "Registrar ajuste" (gated inventory.update) actions here,
                    alongside the ones below.
                -->
                <Button v-if="can('inventory.update')" variant="outline" @click="editOpen = true">Editar</Button>
                <Button v-if="can('inventory.update') && !medication.is_active" variant="outline" @click="activate">
                    Activar
                </Button>
                <Button
                    v-if="can('inventory.update') && medication.is_active"
                    variant="outline"
                    @click="confirmDeactivate"
                >
                    Desactivar
                </Button>
                <Button
                    v-if="can('inventory.delete') && medication.can_be_deleted"
                    variant="destructive"
                    @click="confirmDelete"
                >
                    Eliminar
                </Button>
            </div>
        </div>

        <Card>
            <CardHeader>
                <div class="flex flex-wrap items-center gap-2">
                    <CardTitle>{{ medication.name }}</CardTitle>
                    <Badge v-if="medication.is_low_stock" variant="destructive">Stock bajo</Badge>
                    <Badge v-if="!medication.is_active" variant="secondary">Inactivo</Badge>
                </div>
            </CardHeader>
            <CardContent>
                <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <dt class="text-sm text-muted-foreground">Presentación</dt>
                        <dd class="mt-1 font-medium">{{ medication.presentation }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-muted-foreground">Concentración</dt>
                        <dd class="mt-1 font-medium">{{ medication.concentration }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-muted-foreground">Unidad de dispensación</dt>
                        <dd class="mt-1 font-medium">{{ medication.dispensing_unit }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-muted-foreground">Existencia actual</dt>
                        <dd class="mt-1 font-medium">{{ medication.current_stock }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-muted-foreground">Existencia mínima</dt>
                        <dd class="mt-1 font-medium">{{ medication.minimum_stock }}</dd>
                    </div>
                </dl>
            </CardContent>
        </Card>

        <div class="space-y-3">
            <h2 class="text-lg font-semibold">Historial de movimientos</h2>
            <MovementHistoryTable :movements="movements" />
        </div>
    </div>

    <MedicationFormDialog v-if="can('inventory.update')" v-model:open="editOpen" mode="edit" :medication="medication" />
    <MedicationActionDialog
        v-if="actionOpen"
        :key="actionType"
        v-model:open="actionOpen"
        :medication="medication"
        :action="actionType"
    />
</template>
