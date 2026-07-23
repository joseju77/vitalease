/**
 * Types for the consultation dashboard and detail/form UI. These mirror the
 * JSON shapes returned by `App\Http\Controllers\DashboardController` and the
 * `Inertia::render` props of `App\Http\Controllers\MedicalConsultationController`.
 * Keep both contracts in sync manually; there is no shared code generation
 * between them.
 */

/**
 * The shared consultation list item shape, used by the dashboard's
 * `latestConsultations` prop, the patient summary's `latest_consultations`,
 * and the legacy `dashboard.consultations.latest` JSON endpoint.
 */
export interface ConsultationListItem {
    uuid: string;
    code: string;
    created_at: string;
    diagnosis: string;
    medical_classification: number;
    patient: {
        uuid: string;
        full_name: string;
    };
    physician: {
        name: string;
    };
    can: {
        update: boolean;
        delete: boolean;
    };
}

/** A single patient search result row, restricted to the Scout-indexed fields. */
export interface PatientSearchResult {
    uuid: string;
    first_name: string;
    last_name: string;
    second_last_name: string | null;
    enrollment_number: string | null;
}

/** The patient summary dialog payload: basic data plus the 5 latest consultations. */
export interface PatientSummary {
    patient: {
        uuid: string;
        first_name: string;
        last_name: string;
        second_last_name: string | null;
        birth_date: string;
        sex_at_birth: number;
        blood_type: number;
        enrollment_number: string | null;
    };
    latest_consultations: ConsultationListItem[];
}

/**
 * The one-time flash payload sent by `MedicalConsultationController::{store,update,destroy}`
 * on redirect to `dashboard.index`. `action` is only sent starting in Work
 * Unit 2 (today `store` still flashes `{uuid, code}` without it), so the
 * dashboard toast falls back to `created` when it is absent.
 */
export interface ConsultationFlash {
    uuid: string;
    code: string;
    action?: 'created' | 'updated' | 'deleted';
}
