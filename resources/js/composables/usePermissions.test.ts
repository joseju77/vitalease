import { describe, expect, it, vi } from 'vitest';

const { pageProps } = vi.hoisted(() => {
    return {
        pageProps: {
            props: {
                auth: {
                    is_super_admin: false,
                    permissions: [] as string[],
                },
            },
        },
    };
});

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => pageProps,
}));

import { usePermissions } from './usePermissions';

describe('usePermissions', () => {
    it('grants every permission to a super-admin even with an empty permissions array', () => {
        pageProps.props.auth.is_super_admin = true;
        pageProps.props.auth.permissions = [];

        const { can } = usePermissions();

        expect(can('users.manage')).toBe(true);
        expect(can('anything.not.in.catalog')).toBe(true);
    });

    it('grants only permissions present in the permissions array for a non-super-admin', () => {
        pageProps.props.auth.is_super_admin = false;
        pageProps.props.auth.permissions = ['patients.view'];

        const { can } = usePermissions();

        expect(can('patients.view')).toBe(true);
    });

    it('denies a permission not present in the permissions array', () => {
        pageProps.props.auth.is_super_admin = false;
        pageProps.props.auth.permissions = ['patients.view'];

        const { can } = usePermissions();

        expect(can('patients.delete')).toBe(false);
    });
});
