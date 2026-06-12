export interface User {
    uuid?: string;
    name: string;
    email: string;
    last_login_at: string | null;
    has_access?: boolean;
    created_at: string;
    updated_at: string;
}

export interface Auth {
    user: User | null;
    permissions: string[];
    is_super_admin: boolean;
}

export type AppPageProps<T extends Record<string, unknown> = Record<string, unknown>> = T & {
    auth: Auth;
};
