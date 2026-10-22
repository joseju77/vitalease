<script setup lang="ts">
import { ChevronDown } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
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

const hasGynecologicalTab = computed(() => showsGynecologicalHistory(props.profile));
const generalFieldList = computed(() => generalFields(props.profile));
const contactFieldList = computed(() => contactFields(props.profile));
const otherAilmentFieldList = computed(() => otherAilmentFields(props.profile));
const gynecologicalHistoryFieldList = computed(() => gynecologicalHistoryFields(props.profile));

/** Tabs rendered as a definition list; an empty `fields` list shows the tab's empty state. */
const definitionListTabs = computed(() => [
    { value: 'general', fields: generalFieldList.value, emptyMessage: '' },
    { value: 'contact', fields: contactFieldList.value, emptyMessage: 'Sin datos de contacto registrados.' },
    {
        value: 'other-ailments',
        fields: otherAilmentFieldList.value,
        emptyMessage: 'Sin otros padecimientos registrados.',
    },
    ...(hasGynecologicalTab.value
        ? [
              {
                  value: 'gynecological-history',
                  fields: gynecologicalHistoryFieldList.value,
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
                                <TabsTrigger v-if="hasGynecologicalTab" value="gynecological-history">
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
                                    <span v-if="contact.phone_number !== null" class="tabular-nums">{{
                                        contact.phone_number
                                    }}</span>
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
