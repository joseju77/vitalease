import '@inertiajs/core';
import type { ConsultationFlash } from '@/types/consultations';
import type { MedicationFlash } from '@/types/inventory';

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
            /**
             * One-time confirmation flashed by
             * `MedicationController::flashMedication()` and (later)
             * `InventoryMovementController` on redirect to `inventory.index`
             * or `inventory.show`; the inventory pages render a toast from it.
             */
            medication?: MedicationFlash;
            /**
             * One-time confirmations flashed by
             * `PatientController::{update,disable,enable}` on redirect to
             * `patients.show`; the page renders a toast from each.
             */
            patientUpdated?: boolean;
            patientDisabled?: boolean;
            patientEnabled?: boolean;
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
    /** True when the search fell back to the database during this request. */
    search_degraded?: boolean;
};
