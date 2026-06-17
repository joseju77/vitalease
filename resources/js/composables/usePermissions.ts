import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { AppPageProps } from '@/types';

/**
 * Exposes permission checks against the current authenticated user.
 *
 * A `super-admin` bypasses every ability server-side via `Gate::before()`,
 * which leaves `auth.permissions` empty for that role. `auth.is_super_admin`
 * mirrors that bypass on the client so UI built on `can()` does not hide
 * every control from the one role that can do everything. This is UX only —
 * the real authorization boundary is always enforced server-side.
 */
export function usePermissions() {
    const page = usePage<AppPageProps>();

    function can(permission: string): boolean {
        if (page.props.auth.is_super_admin) {
            return true;
        }

        return page.props.auth.permissions.includes(permission);
    }

    const isSuperAdmin = computed(() => page.props.auth.is_super_admin);

    return { can, isSuperAdmin };
}
