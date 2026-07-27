<script setup lang="ts">
import { computed, ref } from 'vue';
import { FieldLegend, FieldSet } from '@/components/ui/field';
import ComboboxField from '@/components/form/ComboboxField.vue';
import DatePickerField from '@/components/form/DatePickerField.vue';
import SelectField from '@/components/form/SelectField.vue';
import TextField from '@/components/form/TextField.vue';
import { BLOOD_TYPE_LABELS, MARITAL_STATUS_LABELS, SEX_AT_BIRTH_LABELS } from '@/lib/patientLabels';
import type { Enrollment, FamilyMedicalUnit, PatientDemographics } from '@/types/patients';

const props = defineProps<{
    sexAtBirthOptions: number[];
    maritalStatusOptions: number[];
    bloodTypeOptions: number[];
    familyMedicalUnits: FamilyMedicalUnit[];
    enrollments: Enrollment[];
    errors?: Partial<Record<string, string>>;
}>();

const patient = defineModel<PatientDemographics>({ required: true });

const sexAtBirthSelectOptions = computed(() =>
    props.sexAtBirthOptions.map((option) => ({
        value: String(option),
        label: SEX_AT_BIRTH_LABELS[option] ?? String(option),
    })),
);

const maritalStatusSelectOptions = computed(() =>
    props.maritalStatusOptions.map((option) => ({
        value: String(option),
        label: MARITAL_STATUS_LABELS[option] ?? String(option),
    })),
);

const bloodTypeSelectOptions = computed(() =>
    props.bloodTypeOptions.map((option) => ({
        value: String(option),
        label: BLOOD_TYPE_LABELS[option] ?? String(option),
    })),
);

const enrollmentComboboxOptions = computed(() =>
    props.enrollments.map((enrollment) => ({ value: String(enrollment.id), label: enrollment.name })),
);

const familyMedicalUnitComboboxOptions = computed(() =>
    props.familyMedicalUnits.map((unit) => ({ value: String(unit.id), label: unit.name })),
);

/**
 * `patient.enrollment_id`/`enrollment_number` vs. `patient.external_enrollment` are
 * mutually exclusive. This toggle is explicit UI state, not derived from the data:
 * switching to "external" must reveal the text field before the visitor has typed
 * anything into it, so it cannot depend on `external_enrollment` already being non-null.
 */
const enrollmentSource = ref<'catalog' | 'external'>(
    patient.value.external_enrollment !== null ? 'external' : 'catalog',
);

function setEnrollmentSource(source: 'catalog' | 'external') {
    enrollmentSource.value = source;

    if (source === 'catalog') {
        patient.value.external_enrollment = null;
        return;
    }

    patient.value.enrollment_id = null;
    patient.value.enrollment_number = null;
}

/** `patient.family_medical_unit_id` vs. `patient.other_family_medical_unit` are mutually exclusive; same explicit-state rationale as above. */
const familyMedicalUnitSource = ref<'catalog' | 'other'>(
    patient.value.other_family_medical_unit !== null ? 'other' : 'catalog',
);

function setFamilyMedicalUnitSource(source: 'catalog' | 'other') {
    familyMedicalUnitSource.value = source;

    if (source === 'catalog') {
        patient.value.other_family_medical_unit = null;
        return;
    }

    patient.value.family_medical_unit_id = null;
}

const enrollmentIdValue = computed<string | undefined>({
    get: () => (patient.value.enrollment_id === null ? undefined : String(patient.value.enrollment_id)),
    set: (value) => {
        patient.value.enrollment_id = value === undefined ? null : Number(value);
    },
});

const familyMedicalUnitIdValue = computed<string | undefined>({
    get: () =>
        patient.value.family_medical_unit_id === null ? undefined : String(patient.value.family_medical_unit_id),
    set: (value) => {
        patient.value.family_medical_unit_id = value === undefined ? null : Number(value);
    },
});

const sexAtBirthValue = computed<string | undefined>({
    get: () => (patient.value.sex_at_birth === null ? undefined : String(patient.value.sex_at_birth)),
    set: (value) => {
        patient.value.sex_at_birth = value === undefined ? null : Number(value);
    },
});

const maritalStatusValue = computed<string | undefined>({
    get: () => (patient.value.marital_status === null ? undefined : String(patient.value.marital_status)),
    set: (value) => {
        patient.value.marital_status = value === undefined ? null : Number(value);
    },
});

const bloodTypeValue = computed<string | undefined>({
    get: () => (patient.value.blood_type === null ? undefined : String(patient.value.blood_type)),
    set: (value) => {
        patient.value.blood_type = value === undefined ? null : Number(value);
    },
});

/** `TextField` only accepts `string`; these bridge the nullable payload fields. */
function nullableTextModel(get: () => string | null, set: (value: string | null) => void) {
    return computed<string>({
        get: () => get() ?? '',
        set: (value) => set(value === '' ? null : value),
    });
}

const secondLastName = nullableTextModel(
    () => patient.value.second_last_name,
    (value) => (patient.value.second_last_name = value),
);
const enrollmentNumber = nullableTextModel(
    () => patient.value.enrollment_number,
    (value) => (patient.value.enrollment_number = value),
);
const externalEnrollment = nullableTextModel(
    () => patient.value.external_enrollment,
    (value) => (patient.value.external_enrollment = value),
);
const otherFamilyMedicalUnit = nullableTextModel(
    () => patient.value.other_family_medical_unit,
    (value) => (patient.value.other_family_medical_unit = value),
);
</script>

<template>
    <FieldSet class="gap-4">
        <FieldLegend>Datos personales</FieldLegend>

        <div class="grid gap-4 sm:grid-cols-2">
            <TextField
                v-model="patient.first_name"
                label="Nombre(s)"
                placeholder="Nombre(s)"
                :errors="props.errors?.['patient.first_name']"
                maxlength="255"
                autocomplete="given-name"
                required
            />
            <TextField
                v-model="patient.last_name"
                label="Apellido paterno"
                placeholder="Apellido paterno"
                :errors="props.errors?.['patient.last_name']"
                maxlength="255"
                autocomplete="family-name"
                required
            />
            <TextField
                v-model="secondLastName"
                label="Apellido materno"
                placeholder="Apellido materno"
                :errors="props.errors?.['patient.second_last_name']"
                sub-label="Opcional"
                maxlength="255"
                autocomplete="additional-name"
            />
            <DatePickerField
                v-model="patient.birth_date"
                label="Fecha de nacimiento"
                placeholder="Selecciona una fecha"
                :errors="props.errors?.['patient.birth_date']"
                required
            />
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
            <SelectField
                v-model="sexAtBirthValue"
                label="Sexo al nacer"
                :options="sexAtBirthSelectOptions"
                :value-props="{ placeholder: 'Selecciona una opción' }"
                :errors="props.errors?.['patient.sex_at_birth']"
            />

            <SelectField
                v-model="maritalStatusValue"
                label="Estado civil"
                :options="maritalStatusSelectOptions"
                :value-props="{ placeholder: 'Selecciona una opción' }"
                :errors="props.errors?.['patient.marital_status']"
            />

            <SelectField
                v-model="bloodTypeValue"
                label="Tipo de sangre"
                :options="bloodTypeSelectOptions"
                :value-props="{ placeholder: 'Selecciona una opción' }"
                :errors="props.errors?.['patient.blood_type']"
            />
        </div>

        <TextField
            v-model="patient.social_security_number"
            label="Número de seguridad social"
            placeholder="00000000000"
            :errors="props.errors?.['patient.social_security_number']"
            sub-label="11 dígitos"
            maxlength="11"
            required
        />

        <FieldSet class="gap-3">
            <FieldLegend variant="label">Inscripción</FieldLegend>
            <div class="flex gap-4 text-sm">
                <label class="flex items-center gap-2">
                    <input
                        type="radio"
                        name="enrollment-source"
                        :checked="enrollmentSource === 'catalog'"
                        @change="setEnrollmentSource('catalog')"
                    />
                    Inscripción registrada
                </label>
                <label class="flex items-center gap-2">
                    <input
                        type="radio"
                        name="enrollment-source"
                        :checked="enrollmentSource === 'external'"
                        @change="setEnrollmentSource('external')"
                    />
                    Inscripción externa
                </label>
            </div>

            <div v-if="enrollmentSource === 'catalog'" class="grid gap-4 sm:grid-cols-2">
                <ComboboxField
                    v-model="enrollmentIdValue"
                    label="Inscripción"
                    placeholder="Selecciona una inscripción"
                    search-placeholder="Buscar inscripción..."
                    empty-message="No se encontraron inscripciones."
                    :options="enrollmentComboboxOptions"
                    :errors="props.errors?.['patient.enrollment_id']"
                />
                <TextField
                    v-model="enrollmentNumber"
                    label="Número de inscripción"
                    placeholder="Número de inscripción"
                    :errors="props.errors?.['patient.enrollment_number']"
                    maxlength="255"
                />
            </div>
            <TextField
                v-else
                v-model="externalEnrollment"
                label="Inscripción externa"
                placeholder="Nombre de la inscripción externa"
                :errors="props.errors?.['patient.external_enrollment']"
                maxlength="255"
            />
        </FieldSet>

        <FieldSet class="gap-3">
            <FieldLegend variant="label">Unidad médica familiar</FieldLegend>
            <div class="flex gap-4 text-sm">
                <label class="flex items-center gap-2">
                    <input
                        type="radio"
                        name="family-medical-unit-source"
                        :checked="familyMedicalUnitSource === 'catalog'"
                        @change="setFamilyMedicalUnitSource('catalog')"
                    />
                    Unidad registrada
                </label>
                <label class="flex items-center gap-2">
                    <input
                        type="radio"
                        name="family-medical-unit-source"
                        :checked="familyMedicalUnitSource === 'other'"
                        @change="setFamilyMedicalUnitSource('other')"
                    />
                    Otra unidad
                </label>
            </div>

            <ComboboxField
                v-if="familyMedicalUnitSource === 'catalog'"
                v-model="familyMedicalUnitIdValue"
                label="Unidad médica familiar"
                placeholder="Selecciona una unidad"
                search-placeholder="Buscar unidad..."
                empty-message="No se encontraron unidades."
                :options="familyMedicalUnitComboboxOptions"
                :errors="props.errors?.['patient.family_medical_unit_id']"
            />
            <TextField
                v-else
                v-model="otherFamilyMedicalUnit"
                label="Otra unidad médica familiar"
                placeholder="Nombre de la unidad"
                :errors="props.errors?.['patient.other_family_medical_unit']"
                maxlength="255"
            />
        </FieldSet>
    </FieldSet>
</template>
