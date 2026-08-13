/**
 * Human-readable Spanish labels for the permission catalog defined in
 * `app/Enums/Permission.php`. Shared between `UserAuthorizationDialog.vue`,
 * `RoleFormDialog.vue`, and `roles/Index.vue` so the resource/action
 * vocabulary and grouping logic only live in one place.
 */

/** Label for the resource prefix (segment before the dot) of a `resource.action` permission. */
export const RESOURCE_LABELS: Record<string, string> = {
    users: 'Usuarios',
    reports: 'Reportes',
    patients: 'Pacientes',
    consultations: 'Consultas',
    roles: 'Roles',
    inventory: 'Inventario',
};

/** Label for the action suffix (segment after the dot) of a `resource.action` permission. */
export const ACTION_LABELS: Record<string, string> = {
    manage: 'Gestionar',
    generate: 'Generar',
    view: 'Ver',
    create: 'Crear',
    update: 'Actualizar',
    delete: 'Eliminar',
};

/**
 * Label for the action segment only. Used to render each checkbox inside a
 * grouped permission grid, where the enclosing `FieldLegend` already carries
 * the resource label, so repeating it per checkbox would be redundant.
 */
export function permissionActionLabel(permission: string): string {
    const action = permission.split('.')[1] ?? permission;
    return ACTION_LABELS[action] ?? action;
}

/**
 * Full standalone label for a permission, used anywhere a permission needs
 * to be shown outside its resource group (e.g. a flat badge list). `manage`
 * and `generate` actions read naturally as "<Verb> <resource>" (e.g.
 * "Gestionar usuarios"); every other action reads as "<Resource>: <Verb>"
 * (e.g. "Pacientes: Ver").
 */
export function formatPermissionLabel(permission: string): string {
    const [resource, action] = permission.split('.');
    const resourceLabel = RESOURCE_LABELS[resource] ?? resource;
    const actionLabel = ACTION_LABELS[action] ?? action;

    if (action === 'manage' || action === 'generate') {
        return `${actionLabel} ${resourceLabel.toLowerCase()}`;
    }

    return `${resourceLabel}: ${actionLabel}`;
}

/** A permission catalog grouped by resource prefix, in catalog order. */
export interface PermissionGroup {
    resource: string;
    label: string;
    permissions: string[];
}

/**
 * Groups a permission catalog by resource prefix. Shared grouping logic for
 * the checkbox grids in `UserAuthorizationDialog.vue` and `RoleFormDialog.vue`.
 */
export function groupPermissionsByResource(permissionCatalog: string[]): PermissionGroup[] {
    const groups = new Map<string, string[]>();

    for (const permission of permissionCatalog) {
        const resource = permission.split('.')[0];
        const existing = groups.get(resource) ?? [];
        existing.push(permission);
        groups.set(resource, existing);
    }

    return Array.from(groups.entries()).map(([resource, permissions]) => ({
        resource,
        label: RESOURCE_LABELS[resource] ?? resource,
        permissions,
    }));
}
