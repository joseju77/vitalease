import { useForm } from '@inertiajs/vue3';
import { computed, type ComputedRef, type WritableComputedRef } from 'vue';
import { SEX_AT_BIRTH_FEMALE } from '@/lib/patientLabels';
import type { PatientGynecologicalHistory, PatientRegistrationPayload } from '@/types/patients';

/**
 * `gynecological_history` stays a full object in local form state at all
 * times (never `null` while editing) so `GynecologicalHistoryStep` can own a
 * plain, non-nullable `v-model`. It is only dropped from the submitted
 * payload for non-Female patients (see `transformForSubmit`).
 */
export function defaultGynecologicalHistory(): PatientGynecologicalHistory {
    return {
        menarche: null,
        has_cramps: false,
        is_cycle_regular: false,
        cycle_intensity: null,
        cycle_duration: null,
        cycle_flow_level: null,
        last_cycle_date: '',
        sexual_activity_start_age: null,
        contraceptive_method: null,
        last_pap_smear_date: null,
        last_pap_smear_was_positive: null,
        pregnancies: null,
        vaginal_deliveries: null,
        cesareans: null,
        abortions: null,
    };
}

export function emptyPatientAggregate(): PatientRegistrationPayload {
    return {
        patient: {
            first_name: '',
            last_name: '',
            second_last_name: null,
            birth_date: '',
            sex_at_birth: null,
            marital_status: null,
            blood_type: null,
            enrollment_id: null,
            enrollment_number: null,
            external_enrollment: null,
            family_medical_unit_id: null,
            other_family_medical_unit: null,
            social_security_number: '',
        },
        contact_information: {
            address: '',
            phone_number: '',
            personal_email: '',
            institutional_email: null,
            zip_code: null,
            neighborhood_id: null,
        },
        emergency_contacts: [{ name: '', phone_number: '', kinship_type: null }],
        ailments: [],
        other_ailments: null,
        gynecological_history: defaultGynecologicalHistory(),
    };
}

/**
 * Shared aggregate form of the registration and edit pages: defaults (or the
 * stored aggregate), female gating of the gynecological history and the
 * payload transform. Navigation, submission target and copy stay with each
 * page.
 */
export function usePatientAggregateForm(initial?: PatientRegistrationPayload) {
    const data = initial ?? emptyPatientAggregate();
    const form = useForm<PatientRegistrationPayload>({
        ...data,
        gynecological_history: data.gynecological_history ?? defaultGynecologicalHistory(),
    });

    const isFemaleSelected: ComputedRef<boolean> = computed(() => form.patient.sex_at_birth === SEX_AT_BIRTH_FEMALE);

    /** Bridges the payload's nullable `gynecological_history` to a non-nullable `v-model`. */
    const gynecologicalHistoryModel: WritableComputedRef<PatientGynecologicalHistory> = computed({
        get: () => form.gynecological_history ?? defaultGynecologicalHistory(),
        set: (value) => {
            form.gynecological_history = value;
        },
    });

    /**
     * `gynecological_history` is only applicable for Female patients; it must
     * be entirely absent from the submitted payload otherwise, not `null`.
     * `contact_information.zip_code` stays in the payload: the request
     * requires the key even though the controller strips it.
     */
    function transformForSubmit() {
        return form.transform((payload) => {
            const { gynecological_history, ...rest } = payload;

            return isFemaleSelected.value ? { ...rest, gynecological_history } : rest;
        });
    }

    return { form, isFemaleSelected, gynecologicalHistoryModel, transformForSubmit };
}
