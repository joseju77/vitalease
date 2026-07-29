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

/**
 * The read-only patient profile shown on the consultation create, show, and
 * edit pages (`patientProfile` prop, built by
 * `MedicalConsultationController::mapPatientProfile`). Enum fields are raw
 * integers labeled via `lib/patientLabels.ts`; dates are `Y-m-d` strings.
 * Optional one-to-one blocks are `null` and one-to-many blocks are empty
 * arrays when the patient did not register them.
 */
export interface PatientProfile {
    first_name: string;
    last_name: string;
    second_last_name: string | null;
    birth_date: string;
    age: number;
    sex_at_birth: number;
    marital_status: number;
    blood_type: number;
    /** Catalog enrollment name, when the patient has a registered enrollment. */
    enrollment: string | null;
    enrollment_number: string | null;
    external_enrollment: string | null;
    family_medical_unit: { name: string; address: string } | null;
    other_family_medical_unit: string | null;
    social_security_number: string;
    contact_information: {
        address: string;
        phone_number: string;
        personal_email: string;
        institutional_email: string | null;
        neighborhood: string | null;
        zip_code: string | null;
        municipality: string | null;
    } | null;
    emergency_contacts: { name: string; phone_number: string; kinship_type: number }[];
    ailments: { ailment_type: number; diagnosed_at: string; treatment_notes: string | null }[];
    other_ailments: { surgeries: string | null; allergies: string | null; others: string | null } | null;
    gynecological_history: {
        menarche: number;
        has_cramps: boolean;
        is_cycle_regular: boolean;
        cycle_intensity: number;
        cycle_duration: number;
        cycle_flow_level: number;
        last_cycle_date: string;
        sexual_activity_start_age: number | null;
        contraceptive_method: number | null;
        last_pap_smear_date: string | null;
        last_pap_smear_was_positive: boolean | null;
        pregnancies: number;
        vaginal_deliveries: number;
        cesareans: number;
        abortions: number;
    } | null;
}
