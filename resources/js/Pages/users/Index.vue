<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import UserAuthorizationDialog from '@/components/users/UserAuthorizationDialog.vue';
import UserFormDialog from '@/components/users/UserFormDialog.vue';
import { formatDateTime } from '@/lib/formatDateTime';

defineOptions({
    layout: (h: any, page: any) => h(AppLayout, { title: 'Usuarios' }, () => page),
});

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface ManagedUser {
    uuid: string;
    name: string;
    email: string;
    has_access: boolean;
    last_login_at: string | null;
    roles: string[];
    permissions: string[];
}

defineProps<{
    users: {
        data: ManagedUser[];
        links: PaginationLink[];
    };
    roleCatalog: string[];
    permissionCatalog: string[];
}>();

const createOpen = ref(false);
const editingUser = ref<ManagedUser | null>(null);
const authorizingUser = ref<ManagedUser | null>(null);

const editOpen = computed({
    get: () => editingUser.value !== null,
    set: (value: boolean) => {
        if (!value) {
            editingUser.value = null;
        }
    },
});

const authorizationOpen = computed({
    get: () => authorizingUser.value !== null,
    set: (value: boolean) => {
        if (!value) {
            authorizingUser.value = null;
        }
    },
});

function edit(user: ManagedUser) {
    editingUser.value = user;
}

function manageAuthorization(user: ManagedUser) {
    authorizingUser.value = user;
}
</script>

<template>
    <Head title="Usuarios" />

    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-semibold">Usuarios</h1>
            <Button @click="createOpen = true">Nuevo usuario</Button>
        </div>

        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>Nombre</TableHead>
                    <TableHead>Correo</TableHead>
                    <TableHead>Último acceso</TableHead>
                    <TableHead>Acceso</TableHead>
                    <TableHead class="text-right">Acciones</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                <TableEmpty v-if="users.data.length === 0" :colspan="5">No hay usuarios registrados.</TableEmpty>
                <TableRow v-for="user in users.data" :key="user.uuid">
                    <TableCell>{{ user.name }}</TableCell>
                    <TableCell>{{ user.email }}</TableCell>
                    <TableCell>
                        <Badge v-if="!user.last_login_at" variant="outline">Nunca</Badge>
                        <span v-else>{{ formatDateTime(user.last_login_at) }}</span>
                    </TableCell>
                    <TableCell>
                        <Badge :variant="user.has_access ? 'default' : 'outline'">
                            {{ user.has_access ? 'Habilitado' : 'Deshabilitado' }}
                        </Badge>
                    </TableCell>
                    <TableCell class="text-right">
                        <div class="flex justify-end gap-2">
                            <Button variant="outline" size="sm" @click="edit(user)">Editar</Button>
                            <Button variant="outline" size="sm" @click="manageAuthorization(user)">Permisos</Button>
                        </div>
                    </TableCell>
                </TableRow>
            </TableBody>
        </Table>

        <nav v-if="users.links.length > 3" class="flex flex-wrap items-center gap-1">
            <template v-for="link in users.links" :key="link.label">
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

    <UserFormDialog v-model:open="createOpen" mode="create" />
    <UserFormDialog
        v-if="editingUser"
        :key="editingUser.uuid"
        v-model:open="editOpen"
        mode="edit"
        :user="editingUser"
    />
    <UserAuthorizationDialog
        v-if="authorizingUser"
        :key="`authorization-${authorizingUser.uuid}`"
        v-model:open="authorizationOpen"
        :user="authorizingUser"
        :role-catalog="roleCatalog"
        :permission-catalog="permissionCatalog"
    />
</template>
