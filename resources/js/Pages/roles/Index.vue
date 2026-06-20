<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import { destroy } from '@/actions/App/Http/Controllers/RoleController';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import RoleFormDialog from '@/components/roles/RoleFormDialog.vue';
import { formatPermissionLabel } from '@/lib/permissionLabels';

defineOptions({
    layout: (h: any, page: any) => h(AppLayout, { title: 'Roles' }, () => page),
});

interface ManagedRole {
    id: number;
    name: string;
    permissions: string[];
}

const props = defineProps<{
    roles: ManagedRole[];
    permissionCatalog: string[];
}>();

/**
 * The `super-admin` role is protected server-side by both `RolePolicy` and
 * `RoleController` (see `app/Policies/RolePolicy.php`), but the UI must not
 * even offer an action that will 403 — this mirrors that same guard so the
 * "Editar"/"Eliminar" buttons never render for that row.
 */
function isProtected(role: ManagedRole): boolean {
    return role.name === 'super-admin';
}

const createOpen = ref(false);
const editingRole = ref<ManagedRole | null>(null);
const deletingRole = ref<ManagedRole | null>(null);

const editOpen = computed({
    get: () => editingRole.value !== null,
    set: (value: boolean) => {
        if (!value) {
            editingRole.value = null;
        }
    },
});

const deleteOpen = computed({
    get: () => deletingRole.value !== null,
    set: (value: boolean) => {
        if (!value) {
            deletingRole.value = null;
        }
    },
});

function edit(role: ManagedRole) {
    editingRole.value = role;
}

function confirmDelete(role: ManagedRole) {
    deletingRole.value = role;
}

function performDelete() {
    if (!deletingRole.value) {
        return;
    }

    router.delete(destroy(deletingRole.value.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            deletingRole.value = null;
            toast.success('Rol eliminado');
        },
    });
}
</script>

<template>
    <Head title="Roles" />

    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-semibold">Roles</h1>
            <Button @click="createOpen = true">Nuevo rol</Button>
        </div>

        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>Nombre</TableHead>
                    <TableHead>Permisos</TableHead>
                    <TableHead class="text-right">Acciones</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                <TableEmpty v-if="props.roles.length === 0" :colspan="3">No hay roles registrados.</TableEmpty>
                <TableRow v-for="role in props.roles" :key="role.id">
                    <TableCell>{{ role.name }}</TableCell>
                    <TableCell>
                        <div class="flex flex-wrap gap-1">
                            <Badge v-if="isProtected(role)" variant="default">Acceso total</Badge>
                            <Badge v-else-if="role.permissions.length === 0" variant="outline">Sin permisos</Badge>
                            <Badge v-for="permission in role.permissions" :key="permission" variant="secondary">
                                {{ formatPermissionLabel(permission) }}
                            </Badge>
                        </div>
                    </TableCell>
                    <TableCell class="text-right">
                        <div v-if="!isProtected(role)" class="flex justify-end gap-2">
                            <Button variant="outline" size="sm" @click="edit(role)">Editar</Button>
                            <Button variant="outline" size="sm" @click="confirmDelete(role)">Eliminar</Button>
                        </div>
                    </TableCell>
                </TableRow>
            </TableBody>
        </Table>
    </div>

    <RoleFormDialog v-model:open="createOpen" mode="create" :permission-catalog="props.permissionCatalog" />
    <RoleFormDialog
        v-if="editingRole"
        :key="`role-${editingRole.id}`"
        v-model:open="editOpen"
        mode="edit"
        :role="editingRole"
        :permission-catalog="props.permissionCatalog"
    />

    <AlertDialog v-model:open="deleteOpen">
        <AlertDialogContent>
            <AlertDialogHeader>
                <AlertDialogTitle>Eliminar rol</AlertDialogTitle>
                <AlertDialogDescription>
                    ¿Seguro que quieres eliminar el rol "{{ deletingRole?.name }}"? Esta acción no se puede deshacer.
                </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
                <AlertDialogCancel>Cancelar</AlertDialogCancel>
                <Button variant="destructive" @click="performDelete">Eliminar</Button>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>
</template>
