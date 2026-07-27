<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Alert, AlertDescription } from '@/components/ui/alert';
import AilmentsStep from '@/components/patients/AilmentsStep.vue';
import ContactStep from '@/components/patients/ContactStep.vue';
import DemographicsStep from '@/components/patients/DemographicsStep.vue';
import EmergencyContactsStep from '@/components/patients/EmergencyContactsStep.vue';
import GynecologicalHistoryStep from '@/components/patients/GynecologicalHistoryStep.vue';
import { store } from '@/actions/App/Http/Controllers/PatientController';
import { SEX_AT_BIRTH_FEMALE } from '@/lib/patientLabels';
import type {
    PatientGynecologicalHistory,
    PatientRegistrationMetadata,
    PatientRegistrationPayload,
} from '@/types/patients';

defineProps<PatientRegistrationMetadata>();

/**
 * The gynecological history step only joins the sequence for Female
 * patients, so the title list is dynamic. It is always the last step when
 * present.
 */
const STEP_TITLES = computed(() => {
    const titles = ['Datos personales', 'Datos de contacto', 'Contactos de emergencia', 'Padecimientos'];

    if (isFemaleSelected.value) {
        titles.push('Historial ginecológico');
    }

    return titles;
});
const TOTAL_STEPS = computed(() => STEP_TITLES.value.length);

const stepIndex = ref(0);

/**
 * `gynecological_history` stays a full object in local form state at all
 * times (never `null` while editing) so `GynecologicalHistoryStep` can own a
 * plain, non-nullable `v-model` like every other step. It is only
 * destructured out of the submitted payload for non-Female patients in
 * `submit()` below — the payload type itself still allows `null`, which this
 * default object also satisfies.
 */
const DEFAULT_GYNECOLOGICAL_HISTORY: PatientGynecologicalHistory = {
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

/**
 * The whole multi-step aggregate lives in one `useForm`. Navigating between
 * steps only moves `stepIndex`; nothing is submitted until the final POST
 * from `submit()`.
 */
const form = useForm<PatientRegistrationPayload>({
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
    gynecological_history: { ...DEFAULT_GYNECOLOGICAL_HISTORY },
});

const isFemaleSelected = computed(() => form.patient.sex_at_birth === SEX_AT_BIRTH_FEMALE);

/**
 * Bridges the payload's nullable `gynecological_history` type to a
 * non-nullable `v-model` for `GynecologicalHistoryStep`. The getter fallback
 * is a safety net only — `form.gynecological_history` is never actually
 * `null` while the form is being edited.
 */
const gynecologicalHistoryModel = computed<PatientGynecologicalHistory>({
    get: () => form.gynecological_history ?? DEFAULT_GYNECOLOGICAL_HISTORY,
    set: (value) => {
        form.gynecological_history = value;
    },
});

const isFirstStep = computed(() => stepIndex.value === 0);
const isLastStep = computed(() => stepIndex.value === TOTAL_STEPS.value - 1);
const currentStepTitle = computed(() => STEP_TITLES.value[stepIndex.value]);

function goNext() {
    if (!isLastStep.value) {
        stepIndex.value += 1;
    }
}

function goBack() {
    if (!isFirstStep.value) {
        stepIndex.value -= 1;
    }
}

/**
 * Deselecting Female while positioned on the (now removed) gynecological
 * history step would otherwise leave `stepIndex` out of range.
 */
watch(TOTAL_STEPS, (total) => {
    if (stepIndex.value > total - 1) {
        stepIndex.value = total - 1;
    }
});

const page = usePage();
const registrationSucceeded = computed(() => page.flash?.registrationSuccess === true);

/**
 * `registration` is the transaction-catch key `PatientController::store()`
 * uses via `withErrors(['registration' => ...])` — it has no corresponding
 * payload field, so `form.errors` (strictly typed to payload paths) needs a
 * loosened read here.
 */
const registrationError = computed(() => (form.errors as Partial<Record<string, string>>).registration);

/**
 * `gynecological_history` is only applicable for Female patients; it must be
 * entirely absent from the submitted payload otherwise, not sent as `null`.
 * `contact_information.zip_code` stays in the payload — the request
 * requires the key present even though the controller strips it before
 * persistence.
 */
function submit() {
    form.transform((data) => {
        const { gynecological_history, ...rest } = data;

        return isFemaleSelected.value ? { ...rest, gynecological_history } : rest;
    }).post(store().url, {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Registro de paciente" />

    <div
        class="relative min-h-svh overflow-hidden bg-gradient-to-b from-background via-background to-muted/40 px-6 py-12 text-foreground"
    >
        <div class="mx-auto max-w-2xl space-y-4">
            <Alert v-if="registrationSucceeded" variant="default">
                <AlertDescription>Registro completado con éxito. Gracias por registrar al paciente.</AlertDescription>
            </Alert>

            <Card>
                <CardHeader class="items-center justify-items-center gap-1 pb-2 text-center">
                    <p class="text-lg font-semibold text-foreground">Registro de paciente</p>
                    <p class="text-sm text-muted-foreground">
                        Paso {{ stepIndex + 1 }} de {{ TOTAL_STEPS }}: {{ currentStepTitle }}
                    </p>
                </CardHeader>

                <CardContent class="space-y-6">
                    <Alert v-if="registrationError" variant="destructive">
                        <AlertDescription>{{ registrationError }}</AlertDescription>
                    </Alert>

                    <DemographicsStep
                        v-if="stepIndex === 0"
                        v-model="form.patient"
                        :sex-at-birth-options="sexAtBirthOptions"
                        :marital-status-options="maritalStatusOptions"
                        :blood-type-options="bloodTypeOptions"
                        :family-medical-units="familyMedicalUnits"
                        :enrollments="enrollments"
                        :errors="form.errors"
                    />
                    <ContactStep v-else-if="stepIndex === 1" v-model="form.contact_information" :errors="form.errors" />
                    <EmergencyContactsStep
                        v-else-if="stepIndex === 2"
                        v-model="form.emergency_contacts"
                        :kinship-type-options="kinshipTypeOptions"
                        :errors="form.errors"
                    />
                    <AilmentsStep
                        v-else-if="stepIndex === 3"
                        v-model:ailments="form.ailments"
                        v-model:other-ailments="form.other_ailments"
                        :ailment-type-options="ailmentTypeOptions"
                        :errors="form.errors"
                    />
                    <GynecologicalHistoryStep
                        v-else-if="stepIndex === 4 && isFemaleSelected"
                        v-model="gynecologicalHistoryModel"
                        :contraceptive-method-options="contraceptiveMethodOptions"
                        :errors="form.errors"
                    />

                    <div class="flex items-center justify-between pt-2">
                        <Button type="button" variant="outline" :disabled="isFirstStep" @click="goBack">Atrás</Button>
                        <Button v-if="!isLastStep" type="button" @click="goNext">Siguiente</Button>
                        <Button v-else type="button" :disabled="form.processing" @click="submit">
                            {{ form.processing ? 'Enviando...' : 'Enviar registro' }}
                        </Button>
                    </div>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
