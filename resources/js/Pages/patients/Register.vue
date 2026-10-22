<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
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
import { usePatientAggregateForm } from '@/composables/usePatientAggregateForm';
import type { PatientRegistrationMetadata } from '@/types/patients';

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

const { form, isFemaleSelected, gynecologicalHistoryModel, transformForSubmit } = usePatientAggregateForm();

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

function submit() {
    transformForSubmit().post(store().url, {
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
