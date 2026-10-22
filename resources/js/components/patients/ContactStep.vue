<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useDebounceFn } from '@vueuse/core';
import { Field, FieldError, FieldLabel, FieldLegend, FieldSet } from '@/components/ui/field';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import TextField from '@/components/form/TextField.vue';
import { neighborhoods as neighborhoodsAction } from '@/actions/App/Http/Controllers/PatientController';
import type { Neighborhood, PatientContactInformation } from '@/types/patients';

const props = defineProps<{
    errors?: Partial<Record<string, string>>;
    /**
     * Neighborhoods of the prefilled zip code (edit page). They seed the
     * select so the stored neighborhood is selectable before, and even
     * without, the first lookup response.
     */
    initialNeighborhoods?: Neighborhood[];
}>();

const contactInformation = defineModel<PatientContactInformation>({ required: true });

const ZIP_CODE_PATTERN = /^[0-9]{5}$/;

const zipCode = computed<string>({
    get: () => contactInformation.value.zip_code ?? '',
    set: (value) => {
        contactInformation.value.zip_code = value === '' ? null : value;
    },
});

const availableNeighborhoods = ref<Neighborhood[]>([...(props.initialNeighborhoods ?? [])]);
const isLookupLoading = ref(false);
const lookupFailed = ref(false);
let lookupAbortController: AbortController | null = null;

/**
 * Any zip change that leaves the current neighborhood outside the fresh
 * result set clears it, so a stale pairing is never submitted.
 */
function pruneNeighborhoodSelection() {
    const stillAvailable = availableNeighborhoods.value.some(
        (neighborhood) => neighborhood.id === contactInformation.value.neighborhood_id,
    );

    if (!stillAvailable) {
        contactInformation.value.neighborhood_id = null;
    }
}

/**
 * A failed lookup empties the catalog and clears the selection, except for
 * the prefilled lookup of the edit page: there the seeded neighborhoods and
 * the stored selection stay valid.
 */
function registerLookupFailure(keepSelection: boolean) {
    if (keepSelection && availableNeighborhoods.value.length > 0) {
        return;
    }

    availableNeighborhoods.value = [];
    lookupFailed.value = true;
    pruneNeighborhoodSelection();
}

async function lookupNeighborhoods(code: string, keepSelectionOnFailure = false) {
    lookupAbortController?.abort();
    const controller = new AbortController();
    lookupAbortController = controller;

    isLookupLoading.value = true;
    lookupFailed.value = false;

    try {
        const response = await fetch(neighborhoodsAction.url({ query: { zip_code: code } }), {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        });

        if (controller.signal.aborted) {
            return;
        }

        if (!response.ok) {
            registerLookupFailure(keepSelectionOnFailure);
            return;
        }

        const data = (await response.json()) as Neighborhood[];
        availableNeighborhoods.value = data;
        lookupFailed.value = data.length === 0;
        pruneNeighborhoodSelection();
    } catch {
        if (controller.signal.aborted) {
            return;
        }

        registerLookupFailure(keepSelectionOnFailure);
    } finally {
        if (!controller.signal.aborted) {
            isLookupLoading.value = false;
        }
    }
}

const debouncedLookup = useDebounceFn(lookupNeighborhoods, 300);

/**
 * `immediate`: a prefilled zip code (edit page) is looked up right on mount,
 * without the typing debounce, so the stored neighborhood is selectable.
 * An empty prefilled zip leaves the (empty) state untouched.
 */
watch(
    zipCode,
    (value, previous) => {
        const isInitialRun = previous === undefined;

        if (!ZIP_CODE_PATTERN.test(value)) {
            if (isInitialRun) {
                return;
            }

            debouncedLookup.cancel();
            lookupAbortController?.abort();
            isLookupLoading.value = false;
            lookupFailed.value = false;
            availableNeighborhoods.value = [];
            pruneNeighborhoodSelection();
            return;
        }

        if (isInitialRun) {
            lookupNeighborhoods(value, true);
            return;
        }

        debouncedLookup(value);
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    debouncedLookup.cancel();
    lookupAbortController?.abort();
});

const neighborhoodIdValue = computed<string | undefined>({
    get: () =>
        contactInformation.value.neighborhood_id === null
            ? undefined
            : String(contactInformation.value.neighborhood_id),
    set: (value) => {
        contactInformation.value.neighborhood_id = value === undefined ? null : Number(value);
    },
});

const institutionalEmail = computed<string>({
    get: () => contactInformation.value.institutional_email ?? '',
    set: (value) => {
        contactInformation.value.institutional_email = value === '' ? null : value;
    },
});

const showEmptyState = computed(
    () => !isLookupLoading.value && lookupFailed.value && ZIP_CODE_PATTERN.test(zipCode.value),
);
</script>

<template>
    <FieldSet class="gap-4">
        <FieldLegend>Datos de contacto</FieldLegend>

        <TextField
            v-model="contactInformation.address"
            label="Dirección"
            placeholder="Calle, número, referencias"
            :errors="props.errors?.['contact_information.address']"
            autocomplete="street-address"
            required
        />

        <div class="grid gap-4 sm:grid-cols-2">
            <TextField
                v-model="contactInformation.phone_number"
                label="Teléfono"
                placeholder="+521234567890"
                :errors="props.errors?.['contact_information.phone_number']"
                type="tel"
                autocomplete="tel"
                required
            />
            <TextField
                v-model="contactInformation.personal_email"
                label="Correo personal"
                placeholder="ejemplo@correo.mx"
                :errors="props.errors?.['contact_information.personal_email']"
                type="email"
                maxlength="255"
                autocomplete="email"
                required
            />
        </div>

        <TextField
            v-model="institutionalEmail"
            label="Correo institucional"
            placeholder="ejemplo@institucion.mx"
            :errors="props.errors?.['contact_information.institutional_email']"
            sub-label="Opcional"
            type="email"
            maxlength="255"
        />

        <div class="grid gap-4 sm:grid-cols-2">
            <TextField
                v-model="zipCode"
                label="Código postal"
                placeholder="00000"
                :errors="props.errors?.['contact_information.zip_code']"
                sub-label="5 dígitos"
                inputmode="numeric"
                maxlength="5"
                autocomplete="postal-code"
            />

            <Field>
                <FieldLabel for="contact-neighborhood">Colonia</FieldLabel>
                <Select v-model="neighborhoodIdValue" :disabled="!availableNeighborhoods.length">
                    <SelectTrigger id="contact-neighborhood" class="w-full">
                        <SelectValue placeholder="Selecciona una colonia" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="neighborhood in availableNeighborhoods"
                            :key="neighborhood.id"
                            :value="String(neighborhood.id)"
                        >
                            {{ neighborhood.name }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <p v-if="showEmptyState" class="text-sm text-muted-foreground">
                    No se encontraron colonias para este código postal.
                </p>
                <FieldError :errors="[props.errors?.['contact_information.neighborhood_id']]" />
            </Field>
        </div>
    </FieldSet>
</template>
