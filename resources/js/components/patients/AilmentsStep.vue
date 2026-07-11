<script setup lang="ts">
import { computed } from 'vue';
import { FieldLegend, FieldSet } from '@/components/ui/field';
import { Button } from '@/components/ui/button';
import CheckboxField from '@/components/form/CheckboxField.vue';
import SelectField from '@/components/form/SelectField.vue';
import DatePickerField from '@/components/form/DatePickerField.vue';
import TextField from '@/components/form/TextField.vue';
import type { PatientAilment, PatientOtherAilments } from '@/types/patients';

const props = defineProps<{
    ailmentTypeOptions: number[];
    errors?: Partial<Record<string, string>>;
}>();

const ailments = defineModel<PatientAilment[]>('ailments', { required: true });
const otherAilments = defineModel<PatientOtherAilments | null>('otherAilments', { required: true });

/** Spanish labels for `App\Enums\AilmentType`; the backend only sends raw enum values. */
const AILMENT_TYPE_LABELS: Record<number, string> = {
    1: 'Diabetes',
    2: 'Hipertensión',
    3: 'Epilepsia',
    4: 'Neumopatías',
    5: 'Cardiopatías',
    6: 'Cáncer',
};

const ailmentTypeSelectOptions = computed(() =>
    props.ailmentTypeOptions.map((option) => ({
        value: String(option),
        label: AILMENT_TYPE_LABELS[option] ?? String(option),
    })),
);

function emptyAilment(): PatientAilment {
    return { ailment_type: null, diagnosed_at: '', treatment_notes: null };
}

function addAilment() {
    ailments.value = [...ailments.value, emptyAilment()];
}

function removeAilment(index: number) {
    ailments.value = ailments.value.filter((_, currentIndex) => currentIndex !== index);
}

function ailmentTypeValue(ailment: PatientAilment): string | undefined {
    return ailment.ailment_type === null ? undefined : String(ailment.ailment_type);
}

function setAilmentType(ailment: PatientAilment, value: string | undefined) {
    ailment.ailment_type = value === undefined ? null : Number(value);
}

function ailmentErrors(index: number, field: 'ailment_type' | 'diagnosed_at' | 'treatment_notes') {
    return props.errors?.[`ailments.${index}.${field}`];
}

/** `TextField`/`DatePickerField` only accept `string`; these bridge the nullable payload fields. */
function nullableTextModel(get: () => string | null, set: (value: string | null) => void) {
    return computed<string>({
        get: () => get() ?? '',
        set: (value) => set(value === '' ? null : value),
    });
}

const hasOtherAilments = computed({
    get: () => otherAilments.value !== null,
    set: (value: boolean) => {
        otherAilments.value = value ? { surgeries: null, allergies: null, others: null } : null;
    },
});

const surgeries = nullableTextModel(
    () => otherAilments.value?.surgeries ?? null,
    (value) => {
        if (otherAilments.value) {
            otherAilments.value.surgeries = value;
        }
    },
);
const allergies = nullableTextModel(
    () => otherAilments.value?.allergies ?? null,
    (value) => {
        if (otherAilments.value) {
            otherAilments.value.allergies = value;
        }
    },
);
const others = nullableTextModel(
    () => otherAilments.value?.others ?? null,
    (value) => {
        if (otherAilments.value) {
            otherAilments.value.others = value;
        }
    },
);
</script>

<template>
    <FieldSet class="gap-4">
        <FieldLegend>Padecimientos</FieldLegend>

        <div v-for="(ailment, index) in ailments" :key="index" class="rounded-lg border border-border p-4">
            <div class="mb-3 flex items-center justify-between">
                <span class="text-sm font-medium text-foreground">Padecimiento {{ index + 1 }}</span>
                <Button type="button" variant="outline" size="sm" @click="removeAilment(index)">Eliminar</Button>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <SelectField
                    :model-value="ailmentTypeValue(ailment)"
                    label="Padecimiento"
                    :options="ailmentTypeSelectOptions"
                    :value-props="{ placeholder: 'Selecciona una opción' }"
                    :errors="ailmentErrors(index, 'ailment_type')"
                    @update:model-value="(value: string | undefined) => setAilmentType(ailment, value)"
                />
                <DatePickerField
                    v-model="ailment.diagnosed_at"
                    label="Fecha de diagnóstico"
                    placeholder="Selecciona una fecha"
                    :errors="ailmentErrors(index, 'diagnosed_at')"
                    required
                />
                <TextField
                    :model-value="ailment.treatment_notes ?? ''"
                    label="Tratamiento"
                    placeholder="Notas de tratamiento"
                    sub-label="Opcional"
                    :errors="ailmentErrors(index, 'treatment_notes')"
                    @update:model-value="
                        (value: string | number) => (ailment.treatment_notes = value === '' ? null : String(value))
                    "
                />
            </div>
        </div>

        <Button type="button" variant="outline" @click="addAilment">Agregar padecimiento</Button>

        <FieldSet class="gap-3">
            <FieldLegend variant="label">Otros padecimientos</FieldLegend>
            <CheckboxField
                :model-value="hasOtherAilments"
                label="Indicar cirugías, alergias u otros padecimientos"
                @update:model-value="(value: boolean | 'indeterminate') => (hasOtherAilments = value === true)"
            />

            <div v-if="hasOtherAilments" class="grid gap-4 sm:grid-cols-3">
                <TextField
                    v-model="surgeries"
                    label="Cirugías"
                    placeholder="Cirugías previas"
                    :errors="props.errors?.['other_ailments.surgeries']"
                />
                <TextField
                    v-model="allergies"
                    label="Alergias"
                    placeholder="Alergias conocidas"
                    :errors="props.errors?.['other_ailments.allergies']"
                />
                <TextField
                    v-model="others"
                    label="Otros"
                    placeholder="Otros padecimientos"
                    :errors="props.errors?.['other_ailments.others']"
                />
            </div>
            <p v-if="props.errors?.['other_ailments']" class="text-sm text-destructive">
                {{ props.errors['other_ailments'] }}
            </p>
        </FieldSet>
    </FieldSet>
</template>
