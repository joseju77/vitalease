/**
 * Client-side mirror of the permission dependency rules declared in
 * `App\Enums\Permission::requires()`. The server sends the DIRECT dependency
 * map as the `permissionDependencies` Inertia prop; these pure helpers derive
 * the transitive closure from it so `RoleFormDialog.vue` and
 * `UserAuthorizationDialog.vue` cascade checkbox changes the same way the
 * server validates them (see ADR-0011).
 */

import { formatPermissionLabel } from '@/lib/permissionLabels';

/** Direct dependencies keyed by permission value, e.g. `{ 'patients.create': ['patients.view'] }`. */
export type PermissionDependencyMap = Record<string, string[]>;

export interface PermissionCascadeOptions {
    /** Full permission catalog, used to keep the result in catalog order. */
    catalog: string[];
    dependencies: PermissionDependencyMap;
    /**
     * Permissions already granted by another source (e.g. the user's role).
     * They satisfy a requirement without being added to the selection, and
     * keep their dependents selected when a direct permission is removed.
     */
    satisfied?: string[];
}

/** Every permission `permission` depends on, directly or transitively, nearest first. */
export function permissionRequirements(permission: string, dependencies: PermissionDependencyMap): string[] {
    const requirements: string[] = [];
    const pending = [...(dependencies[permission] ?? [])];

    while (pending.length > 0) {
        const requirement = pending.shift() as string;

        if (requirement === permission || requirements.includes(requirement)) {
            continue;
        }

        requirements.push(requirement);
        pending.push(...(dependencies[requirement] ?? []));
    }

    return requirements;
}

/** Every permission that depends on `permission`, directly or transitively. */
export function permissionDependents(permission: string, dependencies: PermissionDependencyMap): string[] {
    return Object.keys(dependencies).filter((candidate) =>
        permissionRequirements(candidate, dependencies).includes(permission),
    );
}

/**
 * Selects `permission` together with every transitive requirement that is
 * neither selected already nor satisfied by `satisfied`.
 */
export function grantPermission(selected: string[], permission: string, options: PermissionCascadeOptions): string[] {
    const satisfied = new Set(options.satisfied ?? []);
    const next = new Set(selected);

    next.add(permission);

    for (const requirement of permissionRequirements(permission, options.dependencies)) {
        if (!satisfied.has(requirement)) {
            next.add(requirement);
        }
    }

    return inCatalogOrder(next, selected, options.catalog);
}

/**
 * Deselects `permission` and every selected permission that (transitively)
 * depends on it and is left with an unsatisfied direct requirement. A
 * requirement still granted by `satisfied` keeps its dependents selected.
 */
export function revokePermission(selected: string[], permission: string, options: PermissionCascadeOptions): string[] {
    const satisfied = new Set(options.satisfied ?? []);
    const dependents = permissionDependents(permission, options.dependencies);
    const next = new Set(selected);

    next.delete(permission);

    let removedAny = true;

    while (removedAny) {
        removedAny = false;

        for (const dependent of dependents) {
            if (!next.has(dependent)) {
                continue;
            }

            const isUnsatisfied = (options.dependencies[dependent] ?? []).some(
                (requirement) => !next.has(requirement) && !satisfied.has(requirement),
            );

            if (isUnsatisfied) {
                next.delete(dependent);
                removedAny = true;
            }
        }
    }

    return inCatalogOrder(next, selected, options.catalog);
}

/**
 * Hint shown next to a permission checkbox listing its DIRECT requirements,
 * e.g. "Requiere: Pacientes: Ver". Requirements in `satisfied` are marked as
 * already granted by the role. Returns `null` when nothing is required.
 */
export function permissionRequirementHint(
    permission: string,
    dependencies: PermissionDependencyMap,
    satisfied: string[] = [],
): string | null {
    const requirements = dependencies[permission] ?? [];

    if (requirements.length === 0) {
        return null;
    }

    const labels = requirements.map((requirement) =>
        satisfied.includes(requirement)
            ? `${formatPermissionLabel(requirement)} (incluido en el rol)`
            : formatPermissionLabel(requirement),
    );

    return `Requiere: ${labels.join(', ')}`;
}

/** Catalog order first, then any selected permission outside the catalog in its original order. */
function inCatalogOrder(permissions: Set<string>, original: string[], catalog: string[]): string[] {
    const ordered = catalog.filter((permission) => permissions.has(permission));
    const outsideCatalog = original.filter(
        (permission) => permissions.has(permission) && !catalog.includes(permission),
    );

    return [...ordered, ...outsideCatalog];
}
