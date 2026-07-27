<script setup lang="ts">
import { ChevronDown } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { formatDate } from '@/lib/formatDateTime';
import {
    AILMENT_TYPE_LABELS,
    BLOOD_TYPE_LABELS,
    CONTRACEPTIVE_METHOD_LABELS,
    enumLabel,
    KINSHIP_TYPE_LABELS,
    MARITAL_STATUS_LABELS,
    SEX_AT_BIRTH_FEMALE,
    SEX_AT_BIRTH_LABELS,
} from '@/lib/patientLabels';
import type { PatientProfile } from '@/types/consultations';

const props = defineProps<{ profile: PatientProfile }>();

/** A read-only definition-list entry; `null` or empty values render as "No registrado". */
interface ProfileField {
    label: string;
    value: string | number | null;
}

const NOT_RECORDED = 'No registrado';
const dtClass = 'text-sm text-muted-foreground';
const ddClass = 'mt-1 font-medium break-words whitespace-pre-line';
const emptyStateClass = 'rounded-lg border border-dashed px-4 py-6 text-center text-sm text-muted-foreground';

function hasValue(value: string | number | null): boolean {
    return value !== null && value !== '';
}

function yesNo(value: boolean): string {
    return value ? 'Sí' : 'No';
}

function withUnit(value: number | null, unit: string): string | null {
    return value === null ? null : `${value} ${unit}`;
}

/** Shown for Female patients, or whenever a history exists regardless of the recorded sex. */
const showsGynecologicalHistory = computed(
    () => props.profile.sex_at_birth === SEX_AT_BIRTH_FEMALE || props.profile.gynecological_history !== null,
);

const generalFields = computed<ProfileField[]>(() => {
    const profile = props.profile;
    const fullName = [profile.first_name, profile.last_name, profile.second_last_name].filter(Boolean).join(' ');
    const enrollmentFields: ProfileField[] =
        profile.enrollment !== null
            ? [
                  { label: 'Inscripción', value: profile.enrollment },
                  { label: 'Número de inscripción', value: profile.enrollment_number },
              ]
            : [{ label: 'Inscripción externa', value: profile.external_enrollment }];
    const familyMedicalUnitFields: ProfileField[] =
        profile.family_medical_unit !== null
            ? [
                  { label: 'Unidad médica familiar', value: profile.family_medical_unit.name },
                  { label: 'Dirección de la unidad', value: profile.family_medical_unit.address },
              ]
            : [{ label: 'Unidad médica familiar (otra)', value: profile.other_family_medical_unit }];

    return [
        { label: 'Nombre completo', value: fullName },
        { label: 'Fecha de nacimiento', value: formatDate(profile.birth_date) },
        { label: 'Edad', value: `${profile.age} años` },
        { label: 'Sexo al nacer', value: enumLabel(SEX_AT_BIRTH_LABELS, profile.sex_at_birth) },
        { label: 'Estado civil', value: enumLabel(MARITAL_STATUS_LABELS, profile.marital_status) },
        { label: 'Tipo de sangre', value: enumLabel(BLOOD_TYPE_LABELS, profile.blood_type) },
        { label: 'Número de seguridad social', value: profile.social_security_number },
        ...enrollmentFields,
        ...familyMedicalUnitFields,
    ];
});

const contactFields = computed<ProfileField[]>(() => {
    const contact = props.profile.contact_information;

    if (contact === null) {
        return [];
    }

    return [
        { label: 'Teléfono', value: contact.phone_number },
        { label: 'Correo personal', value: contact.personal_email },
        { label: 'Correo institucional', value: contact.institutional_email },
        { label: 'Dirección', value: contact.address },
        { label: 'Colonia', value: contact.neighborhood },
        { label: 'Código postal', value: contact.zip_code },
        { label: 'Municipio', value: contact.municipality },
    ];
});

const otherAilmentFields = computed<ProfileField[]>(() => {
    const otherAilments = props.profile.other_ailments;

    if (otherAilments === null) {
        return [];
    }

    return [
        { label: 'Cirugías', value: otherAilments.surgeries },
        { label: 'Alergias', value: otherAilments.allergies },
        { label: 'Otros', value: otherAilments.others },
    ];
});

const gynecologicalHistoryFields = computed<ProfileField[]>(() => {
    const history = props.profile.gynecological_history;

    if (history === null) {
        return [];
    }

    const papSmearResult =
        history.last_pap_smear_was_positive === null
            ? null
            : history.last_pap_smear_was_positive
              ? 'Positivo'
              : 'Negativo';

    return [
        { label: 'Edad de la menarca', value: withUnit(history.menarche, 'años') },
        { label: 'Duración del ciclo', value: withUnit(history.cycle_duration, 'días') },
        { label: 'Intensidad del ciclo', value: `${history.cycle_intensity} de 10` },
        { label: 'Nivel de flujo', value: history.cycle_flow_level },
        { label: 'Presenta cólicos', value: yesNo(history.has_cramps) },
        { label: 'Ciclo regular', value: yesNo(history.is_cycle_regular) },
        { label: 'Fecha del último ciclo', value: formatDate(history.last_cycle_date) },
        { label: 'Edad de inicio de vida sexual', value: withUnit(history.sexual_activity_start_age, 'años') },
        {
            label: 'Método anticonceptivo',
            value:
                history.contraceptive_method === null
                    ? null
                    : enumLabel(CONTRACEPTIVE_METHOD_LABELS, history.contraceptive_method),
        },
        {
            label: 'Fecha del último papanicolaou',
            value: history.last_pap_smear_date === null ? null : formatDate(history.last_pap_smear_date),
        },
        { label: 'Resultado del último papanicolaou', value: papSmearResult },
        { label: 'Embarazos', value: history.pregnancies },
        { label: 'Partos vaginales', value: history.vaginal_deliveries },
        { label: 'Cesáreas', value: history.cesareans },
        { label: 'Abortos', value: history.abortions },
    ];
});

/** Tabs rendered as a definition list; an empty `fields` list shows the tab's empty state. */
const definitionListTabs = computed(() => [
    { value: 'general', fields: generalFields.value, emptyMessage: '' },
    { value: 'contact', fields: contactFields.value, emptyMessage: 'Sin datos de contacto registrados.' },
    { value: 'other-ailments', fields: otherAilmentFields.value, emptyMessage: 'Sin otros padecimientos registrados.' },
    ...(showsGynecologicalHistory.value
        ? [
              {
                  value: 'gynecological-history',
                  fields: gynecologicalHistoryFields.value,
                  emptyMessage: 'Sin historial ginecológico registrado.',
              },
          ]
        : []),
]);

/** The profile starts collapsed so the consultation form stays in focus; staff expand it on demand. */
const isOpen = ref(false);
</script>

<template>
    <Collapsible v-model:open="isOpen" as-child>
        <Card>
            <CardHeader class="flex flex-row items-start justify-between gap-4">
                <div class="space-y-1.5">
                    <CardTitle>Perfil del paciente</CardTitle>
                    <CardDescription>Información registrada por el paciente.</CardDescription>
                </div>
                <CollapsibleTrigger as-child>
                    <Button type="button" variant="ghost" size="sm" class="shrink-0">
                        {{ isOpen ? 'Ocultar' : 'Mostrar' }}
                        <ChevronDown
                            class="size-4 transition-transform"
                            :class="{ 'rotate-180': isOpen }"
                            aria-hidden="true"
                        />
                    </Button>
                </CollapsibleTrigger>
            </CardHeader>
            <CollapsibleContent>
                <CardContent>
                    <Tabs default-value="general" class="gap-4">
                        <div class="-mx-1 overflow-x-auto px-1 pb-1">
                            <TabsList class="w-max">
                                <TabsTrigger value="general">Datos generales</TabsTrigger>
                                <TabsTrigger value="contact">Contacto</TabsTrigger>
                                <TabsTrigger value="emergency-contacts">Contactos de emergencia</TabsTrigger>
                                <TabsTrigger value="ailments">Padecimientos</TabsTrigger>
                                <TabsTrigger value="other-ailments">Otros padecimientos</TabsTrigger>
                                <TabsTrigger v-if="showsGynecologicalHistory" value="gynecological-history">
                                    Historial ginecológico
                                </TabsTrigger>
                            </TabsList>
                        </div>

                        <TabsContent v-for="tab in definitionListTabs" :key="tab.value" :value="tab.value">
                            <dl v-if="tab.fields.length > 0" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                <div v-for="field in tab.fields" :key="field.label">
                                    <dt :class="dtClass">{{ field.label }}</dt>
                                    <dd :class="ddClass">
                                        <template v-if="hasValue(field.value)">{{ field.value }}</template>
                                        <span v-else class="font-normal text-muted-foreground">{{ NOT_RECORDED }}</span>
                                    </dd>
                                </div>
                            </dl>
                            <p v-else :class="emptyStateClass">{{ tab.emptyMessage }}</p>
                        </TabsContent>

                        <TabsContent value="emergency-contacts">
                            <ul v-if="profile.emergency_contacts.length > 0" class="divide-y rounded-lg border">
                                <li
                                    v-for="(contact, index) in profile.emergency_contacts"
                                    :key="index"
                                    class="grid gap-1 p-3 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)] sm:gap-3"
                                >
                                    <span class="font-medium break-words">{{ contact.name }}</span>
                                    <span class="text-sm text-muted-foreground sm:text-base sm:text-foreground">
                                        {{ enumLabel(KINSHIP_TYPE_LABELS, contact.kinship_type) }}
                                    </span>
                                    <span class="tabular-nums">{{ contact.phone_number }}</span>
                                </li>
                            </ul>
                            <p v-else :class="emptyStateClass">Sin contactos de emergencia registrados.</p>
                        </TabsContent>

                        <TabsContent value="ailments">
                            <ul v-if="profile.ailments.length > 0" class="divide-y rounded-lg border">
                                <li
                                    v-for="ailment in profile.ailments"
                                    :key="ailment.ailment_type"
                                    class="space-y-2 p-3"
                                >
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <Badge variant="secondary">{{
                                            enumLabel(AILMENT_TYPE_LABELS, ailment.ailment_type)
                                        }}</Badge>
                                        <span class="text-sm text-muted-foreground">
                                            Diagnosticado el {{ formatDate(ailment.diagnosed_at) }}
                                        </span>
                                    </div>
                                    <p class="text-sm break-words whitespace-pre-line">
                                        <span class="text-muted-foreground">Tratamiento:</span>
                                        {{ ailment.treatment_notes || NOT_RECORDED }}
                                    </p>
                                </li>
                            </ul>
                            <p v-else :class="emptyStateClass">Sin padecimientos registrados.</p>
                        </TabsContent>
                    </Tabs>
                </CardContent>
            </CollapsibleContent>
        </Card>
    </Collapsible>
</template>
