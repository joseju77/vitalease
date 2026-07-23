import '@inertiajs/core';
import type { ConsultationFlash } from '@/types/consultations';

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        flashDataType: {
            /**
             * One-time success confirmation for the public patient
             * registration flow. Never persisted across reloads and never
             * carries patient or medical data.
             */
            registrationSuccess?: boolean;
            /**
             * One-time confirmation flashed by
             * `MedicalConsultationController::{store,update,destroy}` on
             * redirect to `dashboard.index`; the dashboard renders a toast
             * from it.
             */
            consultation?: ConsultationFlash;
        };
    }
}

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
