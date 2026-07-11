/**
 * Types for the public multi-step patient registration flow.
 *
 * These mirror `App\Http\Requests\Patients\StorePatientRegistrationRequest`
 * (the allow-list of accepted nested keys) and the catalog props returned by
 * `App\Http\Controllers\PatientController::create`. Keep both contracts in
 * sync manually; there is no shared code generation between them.
 */

/** Postal code, always a 5-digit string (`zip_codes.code`). */
export type ZipCode = string;

export interface PatientDemographics {
    first_name: string;
    last_name: string;
    second_last_name: string | null;
    birth_date: string;
    sex_at_birth: number | null;
    marital_status: number | null;
    blood_type: number | null;
    enrollment_id: number | null;
    enrollment_number: string | null;
    external_enrollment: string | null;
    family_medical_unit_id: number | null;
    other_family_medical_unit: string | null;
    social_security_number: string;
}

export interface PatientContactInformation {
    address: string;
    phone_number: string;
    personal_email: string;
    institutional_email: string | null;
    /**
     * UI-only field: narrows the neighborhood catalog before submission. It
     * has no column on `patient_contact_information` and is stripped by the
     * controller before `create()`; the request still requires the key.
     */
    zip_code: ZipCode | null;
    neighborhood_id: number | null;
}

export interface PatientEmergencyContact {
    name: string;
    phone_number: string;
    kinship_type: number | null;
}

export interface PatientAilment {
    ailment_type: number | null;
    diagnosed_at: string;
    treatment_notes: string | null;
}

export interface PatientOtherAilments {
    surgeries: string | null;
    allergies: string | null;
    others: string | null;
}

export interface PatientGynecologicalHistory {
    menarche: number | null;
    has_cramps: boolean;
    is_cycle_regular: boolean;
    cycle_intensity: number | null;
    cycle_duration: number | null;
    cycle_flow_level: number | null;
    last_cycle_date: string;
    sexual_activity_start_age: number | null;
    contraceptive_method: number | null;
    last_pap_smear_date: string | null;
    last_pap_smear_was_positive: boolean | null;
    pregnancies: number | null;
    vaginal_deliveries: number | null;
    cesareans: number | null;
    abortions: number | null;
}

/**
 * The complete client-side aggregate. `Register.vue` owns one
 * `useForm<PatientRegistrationPayload>` for the whole step sequence;
 * `other_ailments` and `gynecological_history` are only conditionally
 * included in the final POST (Work Unit D2 destructures them out when not
 * applicable, never sending `null` for an omitted section).
 */
export interface PatientRegistrationPayload {
    patient: PatientDemographics;
    contact_information: PatientContactInformation;
    emergency_contacts: PatientEmergencyContact[];
    ailments: PatientAilment[];
    other_ailments: PatientOtherAilments | null;
    gynecological_history: PatientGynecologicalHistory | null;
}

export interface Neighborhood {
    id: number;
    name: string;
}

export interface FamilyMedicalUnit {
    id: number;
    name: string;
    address: string;
}

export interface Enrollment {
    id: number;
    name: string;
    segment: number;
}

/**
 * Registration metadata props, exactly matching
 * `PatientController::create()`'s flat `Inertia::render` payload.
 */
export interface PatientRegistrationMetadata {
    sexAtBirthOptions: number[];
    maritalStatusOptions: number[];
    bloodTypeOptions: number[];
    kinshipTypeOptions: number[];
    ailmentTypeOptions: number[];
    contraceptiveMethodOptions: number[];
    familyMedicalUnits: FamilyMedicalUnit[];
    enrollments: Enrollment[];
}
