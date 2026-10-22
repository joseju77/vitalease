<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { toast } from 'vue-sonner';
import { updateAuthorization } from '@/actions/App/Http/Controllers/UserController';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import {
    grantPermission,
    permissionRequirementHint,
    revokePermission,
    type PermissionDependencyMap,
} from '@/lib/permissionDependencies';
import { groupPermissionsByResource, permissionActionLabel } from '@/lib/permissionLabels';

/** Sentinel value for "no role assigned" in the single-select trigger. */
const NO_ROLE_VALUE = '__none__';

interface AuthorizableUser {
    uuid: string;
    name: string;
    roles: string[];
    permissions: string[];
}

const props = defineProps<{
    user: AuthorizableUser;
    roleCatalog: string[];
    permissionCatalog: string[];
    permissionDependencies: PermissionDependencyMap;
    /** Permissions granted by each role, keyed by role name. */
    rolePermissions: Record<string, string[]>;
}>();

const open = defineModel<boolean>('open', { default: false });

const form = useForm({
    roles: [...props.user.roles],
    permissions: [...props.user.permissions],
});

/**
 * Refill the form with the target user's current roles/permissions every
 * time the dialog is opened, so a previous assignment session never leaks
 * into the next one.
 */
watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    form.clearErrors();
    form.defaults({
        roles: [...props.user.roles],
        permissions: [...props.user.permissions],
    });
    form.reset();
});

/**
 * Groups the permission catalog by resource prefix. Only `patients.*` and
 * `consultations.*` end up with more than one entry per group; `users.manage`
 * and `reports.generate` naturally render as single-permission groups.
 */
const permissionGroups = computed(() => groupPermissionsByResource(props.permissionCatalog));

const permissionLabel = permissionActionLabel;

const roleValue = computed<string>({
    get: () => form.roles[0] ?? NO_ROLE_VALUE,
    set: (value: string) => {
        form.roles = value === NO_ROLE_VALUE ? [] : [value];
    },
});

function isPermissionChecked(permission: string): boolean {
    return form.permissions.includes(permission);
}

/**
 * Permissions the currently selected role already grants. They satisfy a
 * direct permission's requirements, mirroring the server-side check in
 * `UpdateUserAuthorizationRequest`.
 */
const roleGrantedPermissions = computed(() => form.roles.flatMap((role) => props.rolePermissions[role] ?? []));

/**
 * Checking a permission also checks every requirement the selected role does
 * not already grant; unchecking one also unchecks the direct permissions left
 * without a requirement (see `permissionDependencies.ts`).
 */
function togglePermission(permission: string, checked: boolean) {
    const options = {
        catalog: props.permissionCatalog,
        dependencies: props.permissionDependencies,
        satisfied: roleGrantedPermissions.value,
    };

    form.permissions = checked
        ? grantPermission(form.permissions, permission, options)
        : revokePermission(form.permissions, permission, options);
}

function requirementHint(permission: string): string | null {
    return permissionRequirementHint(permission, props.permissionDependencies, roleGrantedPermissions.value);
}

function submit() {
    form.put(updateAuthorization(props.user.uuid).url, {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            toast.success('Permisos actualizados');
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Permisos de {{ user.name }}</DialogTitle>
                <DialogDescription>Asigna el rol y los permisos directos del usuario.</DialogDescription>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="submit">
                <FieldSet>
                    <Field>
                        <FieldLabel for="user-role">Rol</FieldLabel>
                        <Select v-model="roleValue">
                            <SelectTrigger id="user-role" class="w-full">
                                <SelectValue placeholder="Sin rol" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem :value="NO_ROLE_VALUE">Sin rol</SelectItem>
                                <SelectItem v-for="role in roleCatalog" :key="role" :value="role">
                                    {{ role }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </Field>
                </FieldSet>

                <FieldSet v-for="group in permissionGroups" :key="group.resource">
                    <FieldLegend variant="label">{{ group.label }}</FieldLegend>
                    <FieldGroup class="grid grid-cols-2 gap-3">
                        <Field v-for="permission in group.permissions" :key="permission" orientation="horizontal">
                            <Checkbox
                                :id="`permission-${permission}`"
                                :model-value="isPermissionChecked(permission)"
                                @update:model-value="(value) => togglePermission(permission, value === true)"
                            />
                            <FieldContent>
                                <FieldLabel :for="`permission-${permission}`">
                                    {{ permissionLabel(permission) }}
                                </FieldLabel>
                                <FieldDescription v-if="requirementHint(permission)" class="text-xs">
                                    {{ requirementHint(permission) }}
                                </FieldDescription>
                            </FieldContent>
                        </Field>
                    </FieldGroup>
                </FieldSet>

                <FieldError :errors="form.errors.permissions ? [{ message: form.errors.permissions }] : undefined" />

                <DialogFooter>
                    <Button type="button" variant="outline" @click="open = false">Cancelar</Button>
                    <Button type="submit" :disabled="form.processing">
                        {{ form.processing ? 'Guardando...' : 'Guardar' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
