<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { update } from '@/actions/App/Http/Controllers/PatientController';
import AilmentsStep from '@/components/patients/AilmentsStep.vue';
import ContactStep from '@/components/patients/ContactStep.vue';
import DemographicsStep from '@/components/patients/DemographicsStep.vue';
import EmergencyContactsStep from '@/components/patients/EmergencyContactsStep.vue';
import GynecologicalHistoryStep from '@/components/patients/GynecologicalHistoryStep.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { usePatientAggregateForm } from '@/composables/usePatientAggregateForm';
import AppLayout from '@/layouts/AppLayout.vue';
import { show as patientShow } from '@/routes/patients';
import type { Neighborhood, PatientRegistrationMetadata, PatientRegistrationPayload } from '@/types/patients';

defineOptions({ layout: (h: any, page: any) => h(AppLayout, { title: 'Editar paciente' }, () => page) });

/**
 * The edit page is one stacked form (not a wizard): every registration step
 * component is rendered as a section card, prefilled from `form`, and the
 * whole aggregate goes in a single PUT so every server error is visible next
 * to its field.
 */
const props = defineProps<
    PatientRegistrationMetadata & {
        patient: { uuid: string; full_name: string };
        form: PatientRegistrationPayload;
        neighborhoods: Neighborhood[];
    }
>();

const { form, isFemaleSelected, gynecologicalHistoryModel, transformForSubmit } = usePatientAggregateForm(props.form);

const errors = computed(() => form.errors as Partial<Record<string, string>>);

/** `patient` (no field) is the state error of a patient disabled in the meantime. */
const stateError = computed(() => errors.value.patient);

function sectionHasErrors(prefix: string): boolean {
    return Object.keys(errors.value).some((key) => key === prefix || key.startsWith(`${prefix}.`));
}

const sections = computed(() => [
    { key: 'demographics', title: 'Datos personales', hasErrors: sectionHasErrors('patient') && !stateError.value },
    { key: 'contact', title: 'Datos de contacto', hasErrors: sectionHasErrors('contact_information') },
    { key: 'emergency', title: 'Contactos de emergencia', hasErrors: sectionHasErrors('emergency_contacts') },
    {
        key: 'ailments',
        title: 'Padecimientos',
        hasErrors: sectionHasErrors('ailments') || sectionHasErrors('other_ailments'),
    },
    { key: 'gynecological', title: 'Historial ginecológico', hasErrors: sectionHasErrors('gynecological_history') },
]);

function section(key: string) {
    return sections.value.find((candidate) => candidate.key === key)!;
}

function submit() {
    transformForSubmit().put(update.url(props.patient.uuid), {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head :title="`Editar ${patient.full_name}`" />

    <form class="mx-auto flex w-full max-w-3xl flex-col gap-6" @submit.prevent="submit">
        <header class="space-y-1">
            <h1 class="text-2xl font-semibold">Editar paciente</h1>
            <p class="text-sm text-muted-foreground">{{ patient.full_name }}</p>
        </header>

        <Alert v-if="stateError" variant="destructive" data-testid="edit-state-error">
            <AlertDescription>{{ stateError }}</AlertDescription>
        </Alert>

        <Card data-testid="edit-section-demographics">
            <CardHeader>
                <CardTitle>{{ section('demographics').title }}</CardTitle>
                <p v-if="section('demographics').hasErrors" class="text-sm text-destructive" role="alert">
                    Revisa los campos marcados en esta sección.
                </p>
            </CardHeader>
            <CardContent>
                <DemographicsStep
                    v-model="form.patient"
                    :sex-at-birth-options="sexAtBirthOptions"
                    :marital-status-options="maritalStatusOptions"
                    :blood-type-options="bloodTypeOptions"
                    :family-medical-units="familyMedicalUnits"
                    :enrollments="enrollments"
                    :errors="errors"
                />
            </CardContent>
        </Card>

        <Card data-testid="edit-section-contact">
            <CardHeader>
                <CardTitle>{{ section('contact').title }}</CardTitle>
                <p v-if="section('contact').hasErrors" class="text-sm text-destructive" role="alert">
                    Revisa los campos marcados en esta sección.
                </p>
            </CardHeader>
            <CardContent>
                <ContactStep
                    v-model="form.contact_information"
                    :initial-neighborhoods="neighborhoods"
                    :errors="errors"
                />
            </CardContent>
        </Card>

        <Card data-testid="edit-section-emergency">
            <CardHeader>
                <CardTitle>{{ section('emergency').title }}</CardTitle>
                <p v-if="section('emergency').hasErrors" class="text-sm text-destructive" role="alert">
                    Revisa los campos marcados en esta sección.
                </p>
            </CardHeader>
            <CardContent>
                <EmergencyContactsStep
                    v-model="form.emergency_contacts"
                    :kinship-type-options="kinshipTypeOptions"
                    :errors="errors"
                />
            </CardContent>
        </Card>

        <Card data-testid="edit-section-ailments">
            <CardHeader>
                <CardTitle>{{ section('ailments').title }}</CardTitle>
                <p v-if="section('ailments').hasErrors" class="text-sm text-destructive" role="alert">
                    Revisa los campos marcados en esta sección.
                </p>
            </CardHeader>
            <CardContent>
                <AilmentsStep
                    v-model:ailments="form.ailments"
                    v-model:other-ailments="form.other_ailments"
                    :ailment-type-options="ailmentTypeOptions"
                    :errors="errors"
                />
            </CardContent>
        </Card>

        <Card v-if="isFemaleSelected" data-testid="edit-section-gynecological">
            <CardHeader>
                <CardTitle>{{ section('gynecological').title }}</CardTitle>
                <p v-if="section('gynecological').hasErrors" class="text-sm text-destructive" role="alert">
                    Revisa los campos marcados en esta sección.
                </p>
            </CardHeader>
            <CardContent>
                <GynecologicalHistoryStep
                    v-model="gynecologicalHistoryModel"
                    :contraceptive-method-options="contraceptiveMethodOptions"
                    :errors="errors"
                />
            </CardContent>
        </Card>

        <div class="flex items-center justify-end gap-2">
            <Button variant="outline" as-child>
                <Link :href="patientShow(patient.uuid).url">Cancelar</Link>
            </Button>
            <Button type="submit" :disabled="form.processing">
                {{ form.processing ? 'Guardando...' : 'Guardar cambios' }}
            </Button>
        </div>
    </form>
</template>
