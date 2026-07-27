<script setup lang="ts">
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Field, FieldError, FieldLabel, FieldLegend, FieldSet } from '@/components/ui/field';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import TextField from '@/components/form/TextField.vue';
import { KINSHIP_TYPE_LABELS } from '@/lib/patientLabels';
import type { PatientEmergencyContact } from '@/types/patients';

const props = defineProps<{
    kinshipTypeOptions: number[];
    errors?: Partial<Record<string, string>>;
}>();

const emergencyContacts = defineModel<PatientEmergencyContact[]>({ required: true });

function emptyContact(): PatientEmergencyContact {
    return { name: '', phone_number: '', kinship_type: null };
}

function addContact() {
    emergencyContacts.value = [...emergencyContacts.value, emptyContact()];
}

function removeContact(index: number) {
    emergencyContacts.value = emergencyContacts.value.filter((_, currentIndex) => currentIndex !== index);
}

const canRemove = computed(() => emergencyContacts.value.length > 1);

function kinshipTypeValue(contact: PatientEmergencyContact): string | undefined {
    return contact.kinship_type === null ? undefined : String(contact.kinship_type);
}

function setKinshipType(contact: PatientEmergencyContact, value: string | undefined) {
    contact.kinship_type = value === undefined ? null : Number(value);
}

function contactErrors(index: number, field: 'name' | 'phone_number' | 'kinship_type') {
    return props.errors?.[`emergency_contacts.${index}.${field}`];
}
</script>

<template>
    <FieldSet class="gap-4">
        <FieldLegend>Contactos de emergencia</FieldLegend>
        <FieldError :errors="[props.errors?.['emergency_contacts']]" />

        <div v-for="(contact, index) in emergencyContacts" :key="index" class="rounded-lg border border-border p-4">
            <div class="mb-3 flex items-center justify-between">
                <span class="text-sm font-medium text-foreground">Contacto {{ index + 1 }}</span>
                <Button type="button" variant="outline" size="sm" :disabled="!canRemove" @click="removeContact(index)">
                    Eliminar
                </Button>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <TextField
                    v-model="contact.name"
                    label="Nombre"
                    placeholder="Nombre completo"
                    :errors="contactErrors(index, 'name')"
                    maxlength="255"
                    required
                />
                <TextField
                    v-model="contact.phone_number"
                    label="Teléfono"
                    placeholder="+521234567890"
                    :errors="contactErrors(index, 'phone_number')"
                    type="tel"
                    required
                />
                <Field>
                    <FieldLabel :for="`emergency-contact-kinship-${index}`">Parentesco</FieldLabel>
                    <Select
                        :model-value="kinshipTypeValue(contact)"
                        @update:model-value="(value) => setKinshipType(contact, value as string | undefined)"
                    >
                        <SelectTrigger :id="`emergency-contact-kinship-${index}`" class="w-full">
                            <SelectValue placeholder="Selecciona una opción" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="option in kinshipTypeOptions" :key="option" :value="String(option)">
                                {{ KINSHIP_TYPE_LABELS[option] ?? option }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <FieldError :errors="[contactErrors(index, 'kinship_type')]" />
                </Field>
            </div>
        </div>

        <Button type="button" variant="outline" @click="addContact">Agregar contacto de emergencia</Button>
    </FieldSet>
</template>
