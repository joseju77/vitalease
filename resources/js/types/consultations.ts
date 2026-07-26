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

/** The one-time consultation mutation flash payload. */
export interface ConsultationFlash {
    uuid: string;
    code: string;
    action: 'created' | 'updated' | 'deleted';
}

export interface ConsultationPatient {
    uuid: string;
    full_name: string;
    enrollment_number: string | null;
}

export interface TreatmentRow {
    medication: string;
    dose: string;
    frequency: string;
    duration: string;
}

/** Vital-sign inputs start as strings and become numbers once edited (`type="number"` + `v-model`). */
export interface VitalSignsForm {
    weight: string | number;
    height: string | number;
    blood_pressure_systolic: string | number;
    blood_pressure_diastolic: string | number;
    heart_rate: string | number;
    respiratory_rate: string | number;
    temperature: string | number;
    oxygen_saturation: string | number;
    glasgow: string | number;
    glucose: string | number;
}

export interface PhysicalExaminationForm {
    neurological: string;
    head_neck: string;
    thorax_cardiopulmonary: string;
    abdomen: string;
    extremities: string;
    cabinet_laboratory: string;
}

export interface RegulationForm {
    transfer_type: number | null;
    regulated_at: string;
    ambulance_registration: string;
    regulation_number: string;
    clinic_id: string;
    receiver_physician: string;
}

export interface ConsultationFormPayload {
    patient_uuid?: string;
    consultation: { current_condition: string; diagnosis: string };
    condition: number | null;
    prognosis: number | null;
    medical_classification: number | null;
    treatment: TreatmentRow[];
    vital_signs: VitalSignsForm;
    physical_examination: PhysicalExaminationForm;
    regulation: RegulationForm | null;
}

export interface ConsultationAggregate extends Omit<ConsultationFormPayload, 'patient_uuid'> {
    uuid: string;
    code: string;
    created_at: string;
    patient: ConsultationPatient;
    physician: { name: string };
    vital_signs: VitalSignsForm;
    regulation: RegulationForm | null;
    can: { update: boolean; delete: boolean };
}

export interface ConsultationFormOptions {
    medicalStateOptions: number[];
    medicalClassificationOptions: number[];
    transferTypeOptions: number[];
}
