import type { LucideIcon } from '@lucide/vue';
import { Boxes, ChartBar, ShieldCheck, Stethoscope, Users, UsersRound } from '@lucide/vue';
import { index as dashboardIndex } from '@/routes/dashboard';
import { index as inventoryIndex } from '@/routes/inventory';
import { index as rolesIndex } from '@/routes/roles';
import { index as usersIndex } from '@/routes/users';

/** A single sidebar link, gated behind a permission from `app/Enums/Permission.php`. */
export interface NavItem {
    title: string;
    href: string;
    icon: LucideIcon;
    permission: string;
}

/** A titled group of sidebar links. Ungrouped top-level items omit `label`. */
export interface NavGroup {
    label?: string;
    items: NavItem[];
}

/**
 * Static sidebar structure for the app shell. Order and Spanish labels match
 * the spec exactly. Items without a real destination yet point to `#` and
 * render as plain, non-disabled links (see `NavMain.vue`).
 */
export const NAV_GROUPS: NavGroup[] = [
    {
        items: [{ title: 'Pacientes', href: '#', icon: UsersRound, permission: 'patients.view' }],
    },
    {
        label: 'ATENCIÓN MÉDICA',
        items: [
            { title: 'Consultas', href: dashboardIndex().url, icon: Stethoscope, permission: 'consultations.view' },
            { title: 'Inventario', href: inventoryIndex().url, icon: Boxes, permission: 'inventory.view' },
            { title: 'Reportes y estadísticas', href: '#', icon: ChartBar, permission: 'reports.generate' },
        ],
    },
    {
        label: 'ADMINISTRACIÓN GENERAL',
        items: [
            { title: 'Usuarios', href: usersIndex().url, icon: Users, permission: 'users.manage' },
            { title: 'Roles', href: rolesIndex().url, icon: ShieldCheck, permission: 'roles.manage' },
        ],
    },
];

/**
 * Filters nav groups down to the items the current user is permitted to see,
 * then drops any group left with no items (its label included). Pure
 * function — order of groups and items is always preserved.
 */
export function visibleNavGroups(groups: NavGroup[], can: (permission: string) => boolean): NavGroup[] {
    return groups
        .map((group) => ({
            ...group,
            items: group.items.filter((item) => can(item.permission)),
        }))
        .filter((group) => group.items.length > 0);
}
