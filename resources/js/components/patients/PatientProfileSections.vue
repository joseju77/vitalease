<script setup lang="ts">
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatDate } from '@/lib/formatDateTime';
import { AILMENT_TYPE_LABELS, enumLabel, KINSHIP_TYPE_LABELS } from '@/lib/patientLabels';
import {
    contactFields,
    generalFields,
    gynecologicalHistoryFields,
    hasValue,
    otherAilmentFields,
    showsGynecologicalHistory,
} from '@/lib/patientProfileFields';
import type { PatientProfile } from '@/types/consultations';

const props = defineProps<{ profile: PatientProfile }>();

const NOT_RECORDED = 'No registrado';
const dtClass = 'text-sm text-muted-foreground';
const ddClass = 'mt-1 font-medium break-words whitespace-pre-line';
const emptyStateClass = 'rounded-lg border border-dashed px-4 py-6 text-center text-sm text-muted-foreground';

/** Sections rendered as a definition list; an empty `fields` list shows the section's empty message. */
const definitionListSections = computed(() => [
    { key: 'general', title: 'Datos generales', fields: generalFields(props.profile), emptyMessage: '' },
    {
        key: 'contact',
        title: 'Contacto',
        fields: contactFields(props.profile),
        emptyMessage: 'Sin datos de contacto registrados.',
    },
]);

const otherAilmentsFields = computed(() => otherAilmentFields(props.profile));
const hasGynecologicalSection = computed(() => showsGynecologicalHistory(props.profile));
const gynecologicalFields = computed(() => gynecologicalHistoryFields(props.profile));
</script>

<template>
    <div class="grid gap-6">
        <Card v-for="section in definitionListSections" :key="section.key" :data-testid="`section-${section.key}`">
            <CardHeader>
                <CardTitle>{{ section.title }}</CardTitle>
            </CardHeader>
            <CardContent>
                <dl v-if="section.fields.length > 0" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div v-for="field in section.fields" :key="field.label">
                        <dt :class="dtClass">{{ field.label }}</dt>
                        <dd :class="ddClass">
                            <template v-if="hasValue(field.value)">{{ field.value }}</template>
                            <span v-else class="font-normal text-muted-foreground">{{ NOT_RECORDED }}</span>
                        </dd>
                    </div>
                </dl>
                <p v-else :class="emptyStateClass">{{ section.emptyMessage }}</p>
            </CardContent>
        </Card>

        <Card data-testid="section-emergency-contacts">
            <CardHeader>
                <CardTitle>Contactos de emergencia</CardTitle>
            </CardHeader>
            <CardContent>
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
                        <span v-if="contact.phone_number !== null" class="tabular-nums">{{
                            contact.phone_number
                        }}</span>
                    </li>
                </ul>
                <p v-else :class="emptyStateClass">Sin contactos de emergencia registrados.</p>
            </CardContent>
        </Card>

        <Card data-testid="section-ailments">
            <CardHeader>
                <CardTitle>Padecimientos</CardTitle>
            </CardHeader>
            <CardContent>
                <ul v-if="profile.ailments.length > 0" class="divide-y rounded-lg border">
                    <li v-for="ailment in profile.ailments" :key="ailment.ailment_type" class="space-y-2 p-3">
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
            </CardContent>
        </Card>

        <Card data-testid="section-other-ailments">
            <CardHeader>
                <CardTitle>Otros padecimientos</CardTitle>
            </CardHeader>
            <CardContent>
                <dl v-if="otherAilmentsFields.length > 0" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div v-for="field in otherAilmentsFields" :key="field.label">
                        <dt :class="dtClass">{{ field.label }}</dt>
                        <dd :class="ddClass">
                            <template v-if="hasValue(field.value)">{{ field.value }}</template>
                            <span v-else class="font-normal text-muted-foreground">{{ NOT_RECORDED }}</span>
                        </dd>
                    </div>
                </dl>
                <p v-else :class="emptyStateClass">Sin otros padecimientos registrados.</p>
            </CardContent>
        </Card>

        <Card v-if="hasGynecologicalSection" data-testid="section-gynecological-history">
            <CardHeader>
                <CardTitle>Antecedentes gineco-obstétricos</CardTitle>
            </CardHeader>
            <CardContent>
                <dl v-if="gynecologicalFields.length > 0" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div v-for="field in gynecologicalFields" :key="field.label">
                        <dt :class="dtClass">{{ field.label }}</dt>
                        <dd :class="ddClass">
                            <template v-if="hasValue(field.value)">{{ field.value }}</template>
                            <span v-else class="font-normal text-muted-foreground">{{ NOT_RECORDED }}</span>
                        </dd>
                    </div>
                </dl>
                <p v-else :class="emptyStateClass">Sin historial ginecológico registrado.</p>
            </CardContent>
        </Card>
    </div>
</template>
