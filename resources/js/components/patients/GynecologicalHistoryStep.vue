<script setup lang="ts">
import { computed } from 'vue';
import { FieldLegend, FieldSet } from '@/components/ui/field';
import CheckboxField from '@/components/form/CheckboxField.vue';
import DatePickerField from '@/components/form/DatePickerField.vue';
import SelectField from '@/components/form/SelectField.vue';
import TextField from '@/components/form/TextField.vue';
import type { PatientGynecologicalHistory } from '@/types/patients';

const props = defineProps<{
    contraceptiveMethodOptions: number[];
    errors?: Partial<Record<string, string>>;
}>();

const history = defineModel<PatientGynecologicalHistory>({ required: true });

/** Spanish labels for `App\Enums\ContraceptiveMethod`; the backend only sends raw enum values. */
const CONTRACEPTIVE_METHOD_LABELS: Record<number, string> = {
    1: 'Condón',
    2: 'Píldoras orales',
    3: 'DIU',
    4: 'Implante',
    5: 'Inyección',
    6: 'Parche',
    7: 'Anillo',
    8: 'Métodos de barrera',
    9: 'Esterilización',
    10: 'Otro',
    11: 'Ninguno',
};

const contraceptiveMethodSelectOptions = computed(() =>
    props.contraceptiveMethodOptions.map((option) => ({
        value: String(option),
        label: CONTRACEPTIVE_METHOD_LABELS[option] ?? String(option),
    })),
);

/** Bridges a nullable numeric payload field to `TextField`'s string-only model. */
function nullableNumberModel(get: () => number | null, set: (value: number | null) => void) {
    return computed<string>({
        get: () => (get() === null ? '' : String(get())),
        set: (value) => set(value === '' ? null : Number(value)),
    });
}

/** Bridges a nullable date payload field to `DatePickerField`'s `string | string[] | undefined` model. */
function nullableDateModel(get: () => string | null, set: (value: string | null) => void) {
    return computed({
        get: (): string | undefined => get() ?? undefined,
        set: (value: string | string[] | undefined) => set(Array.isArray(value) ? (value[0] ?? null) : (value ?? null)),
    });
}

const menarche = nullableNumberModel(
    () => history.value.menarche,
    (value) => (history.value.menarche = value),
);
const cycleIntensity = nullableNumberModel(
    () => history.value.cycle_intensity,
    (value) => (history.value.cycle_intensity = value),
);
const cycleDuration = nullableNumberModel(
    () => history.value.cycle_duration,
    (value) => (history.value.cycle_duration = value),
);
const cycleFlowLevel = nullableNumberModel(
    () => history.value.cycle_flow_level,
    (value) => (history.value.cycle_flow_level = value),
);
const sexualActivityStartAge = nullableNumberModel(
    () => history.value.sexual_activity_start_age,
    (value) => (history.value.sexual_activity_start_age = value),
);
const pregnancies = nullableNumberModel(
    () => history.value.pregnancies,
    (value) => (history.value.pregnancies = value),
);
const vaginalDeliveries = nullableNumberModel(
    () => history.value.vaginal_deliveries,
    (value) => (history.value.vaginal_deliveries = value),
);
const cesareans = nullableNumberModel(
    () => history.value.cesareans,
    (value) => (history.value.cesareans = value),
);
const abortions = nullableNumberModel(
    () => history.value.abortions,
    (value) => (history.value.abortions = value),
);

const lastPapSmearDate = nullableDateModel(
    () => history.value.last_pap_smear_date,
    (value) => (history.value.last_pap_smear_date = value),
);

const contraceptiveMethodValue = computed<string | undefined>({
    get: () => (history.value.contraceptive_method === null ? undefined : String(history.value.contraceptive_method)),
    set: (value) => {
        history.value.contraceptive_method = value === undefined ? null : Number(value);
    },
});

const lastPapSmearWasPositiveValue = computed<string | undefined>({
    get: () =>
        history.value.last_pap_smear_was_positive === null
            ? undefined
            : history.value.last_pap_smear_was_positive
              ? '1'
              : '0',
    set: (value) => {
        history.value.last_pap_smear_was_positive = value === undefined ? null : value === '1';
    },
});

const PAP_SMEAR_RESULT_OPTIONS = [
    { value: '1', label: 'Positivo' },
    { value: '0', label: 'Negativo' },
];
</script>

<template>
    <FieldSet class="gap-4">
        <FieldLegend>Historial ginecológico</FieldLegend>

        <div class="grid gap-4 sm:grid-cols-3">
            <TextField
                v-model="menarche"
                label="Edad de la menarca"
                placeholder="Años"
                type="number"
                inputmode="numeric"
                :errors="props.errors?.['gynecological_history.menarche']"
                required
            />
            <TextField
                v-model="cycleDuration"
                label="Duración del ciclo"
                sub-label="Días"
                type="number"
                inputmode="numeric"
                :errors="props.errors?.['gynecological_history.cycle_duration']"
                required
            />
            <TextField
                v-model="cycleIntensity"
                label="Intensidad del ciclo"
                sub-label="1 a 10"
                type="number"
                inputmode="numeric"
                :errors="props.errors?.['gynecological_history.cycle_intensity']"
                required
            />
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
            <CheckboxField
                :model-value="history.has_cramps"
                label="Presenta cólicos"
                :errors="props.errors?.['gynecological_history.has_cramps']"
                @update:model-value="(value: boolean | 'indeterminate') => (history.has_cramps = value === true)"
            />
            <CheckboxField
                :model-value="history.is_cycle_regular"
                label="Ciclo regular"
                :errors="props.errors?.['gynecological_history.is_cycle_regular']"
                @update:model-value="(value: boolean | 'indeterminate') => (history.is_cycle_regular = value === true)"
            />
            <TextField
                v-model="cycleFlowLevel"
                label="Nivel de flujo"
                sub-label="1 o mayor"
                type="number"
                inputmode="numeric"
                :errors="props.errors?.['gynecological_history.cycle_flow_level']"
                required
            />
        </div>

        <DatePickerField
            v-model="history.last_cycle_date"
            label="Fecha del último ciclo"
            placeholder="Selecciona una fecha"
            :errors="props.errors?.['gynecological_history.last_cycle_date']"
            required
        />

        <div class="grid gap-4 sm:grid-cols-2">
            <TextField
                v-model="sexualActivityStartAge"
                label="Edad de inicio de vida sexual"
                sub-label="Opcional"
                type="number"
                inputmode="numeric"
                :errors="props.errors?.['gynecological_history.sexual_activity_start_age']"
            />
            <SelectField
                v-model="contraceptiveMethodValue"
                label="Método anticonceptivo"
                :options="contraceptiveMethodSelectOptions"
                :value-props="{ placeholder: 'Selecciona una opción' }"
                :errors="props.errors?.['gynecological_history.contraceptive_method']"
            />
        </div>

        <FieldSet class="gap-3">
            <FieldLegend variant="label">Último papanicolaou</FieldLegend>
            <p class="text-sm text-muted-foreground">
                Opcional: indica la fecha y el resultado juntos, o deja ambos en blanco.
            </p>
            <div class="grid gap-4 sm:grid-cols-2">
                <DatePickerField
                    v-model="lastPapSmearDate"
                    label="Fecha del último papanicolaou"
                    placeholder="Selecciona una fecha"
                    sub-label="Opcional"
                    :errors="props.errors?.['gynecological_history.last_pap_smear_date']"
                />
                <SelectField
                    v-model="lastPapSmearWasPositiveValue"
                    label="Resultado"
                    sub-label="Opcional"
                    :options="PAP_SMEAR_RESULT_OPTIONS"
                    :value-props="{ placeholder: 'Selecciona una opción' }"
                    :errors="props.errors?.['gynecological_history.last_pap_smear_was_positive']"
                />
            </div>
        </FieldSet>

        <FieldSet class="gap-3">
            <FieldLegend variant="label">Embarazos</FieldLegend>
            <div class="grid gap-4 sm:grid-cols-4">
                <TextField
                    v-model="pregnancies"
                    label="Embarazos"
                    type="number"
                    inputmode="numeric"
                    :errors="props.errors?.['gynecological_history.pregnancies']"
                    required
                />
                <TextField
                    v-model="vaginalDeliveries"
                    label="Partos vaginales"
                    type="number"
                    inputmode="numeric"
                    :errors="props.errors?.['gynecological_history.vaginal_deliveries']"
                    required
                />
                <TextField
                    v-model="cesareans"
                    label="Cesáreas"
                    type="number"
                    inputmode="numeric"
                    :errors="props.errors?.['gynecological_history.cesareans']"
                    required
                />
                <TextField
                    v-model="abortions"
                    label="Abortos"
                    type="number"
                    inputmode="numeric"
                    :errors="props.errors?.['gynecological_history.abortions']"
                    required
                />
            </div>
        </FieldSet>
    </FieldSet>
</template>
