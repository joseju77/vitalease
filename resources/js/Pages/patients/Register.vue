<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import ContactStep from '@/components/patients/ContactStep.vue';
import DemographicsStep from '@/components/patients/DemographicsStep.vue';
import EmergencyContactsStep from '@/components/patients/EmergencyContactsStep.vue';
import type { PatientRegistrationMetadata, PatientRegistrationPayload } from '@/types/patients';

defineProps<PatientRegistrationMetadata>();

/**
 * Only steps 1-3 (demographics, contact, emergency contacts) render in this
 * commit. Ailments and gynecological history join the sequence in the
 * follow-up D2 work unit, together with submit wiring.
 */
const STEP_TITLES = ['Datos personales', 'Datos de contacto', 'Contactos de emergencia'] as const;
const TOTAL_STEPS = STEP_TITLES.length;

const stepIndex = ref(0);

/**
 * The whole multi-step aggregate lives in one `useForm`. Navigating between
 * steps only moves `stepIndex`; nothing is submitted or persisted until the
 * final POST added in D2.
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
    gynecological_history: null,
});

const isFirstStep = computed(() => stepIndex.value === 0);
const isLastStep = computed(() => stepIndex.value === TOTAL_STEPS - 1);
const currentStepTitle = computed(() => STEP_TITLES[stepIndex.value]);

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
</script>

<template>
    <Head title="Registro de paciente" />

    <div
        class="relative min-h-svh overflow-hidden bg-gradient-to-b from-background via-background to-muted/40 px-6 py-12 text-foreground"
    >
        <div class="mx-auto max-w-2xl">
            <Card>
                <CardHeader class="items-center justify-items-center gap-1 pb-2 text-center">
                    <p class="text-lg font-semibold text-foreground">Registro de paciente</p>
                    <p class="text-sm text-muted-foreground">
                        Paso {{ stepIndex + 1 }} de {{ TOTAL_STEPS }}: {{ currentStepTitle }}
                    </p>
                </CardHeader>

                <CardContent class="space-y-6">
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

                    <div class="flex items-center justify-between pt-2">
                        <Button type="button" variant="outline" :disabled="isFirstStep" @click="goBack">Atrás</Button>
                        <Button type="button" :disabled="isLastStep" @click="goNext">Siguiente</Button>
                    </div>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
