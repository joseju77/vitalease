<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { patientSummary as patientSummaryAction } from '@/actions/App/Http/Controllers/DashboardController';
import { create, show } from '@/actions/App/Http/Controllers/MedicalConsultationController';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Skeleton } from '@/components/ui/skeleton';
import { medicalClassificationLabel } from '@/lib/consultationLabels';
import { formatDateTime } from '@/lib/formatDateTime';
import type { PatientSearchResult, PatientSummary } from '@/types/consultations';

const props = defineProps<{
    patient: PatientSearchResult;
    canCreateConsultation: boolean;
}>();

const open = defineModel<boolean>('open', { default: false });

/** Spanish labels for `App\Enums\SexAtBirth`; single consumer here (Stage 3 `DemographicsStep.vue` precedent for inline enum maps). */
const SEX_AT_BIRTH_LABELS: Record<number, string> = {
    1: 'Masculino',
    2: 'Femenino',
};

/** Spanish labels for `App\Enums\BloodType`; single consumer here. */
const BLOOD_TYPE_LABELS: Record<number, string> = {
    1: 'A+',
    2: 'A-',
    3: 'B+',
    4: 'B-',
    5: 'AB+',
    6: 'AB-',
    7: 'O+',
    8: 'O-',
};

const summary = ref<PatientSummary | null>(null);
const isLoading = ref(false);
const hasFailed = ref(false);
let abortController: AbortController | null = null;

async function fetchSummary() {
    abortController?.abort();
    const controller = new AbortController();
    abortController = controller;

    isLoading.value = true;
    hasFailed.value = false;
    summary.value = null;

    try {
        const response = await fetch(patientSummaryAction.url(props.patient.uuid), {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        });

        if (controller.signal.aborted) {
            return;
        }

        if (!response.ok) {
            hasFailed.value = true;
            return;
        }

        summary.value = (await response.json()) as PatientSummary;
    } catch {
        if (controller.signal.aborted) {
            return;
        }

        hasFailed.value = true;
    } finally {
        if (!controller.signal.aborted) {
            isLoading.value = false;
        }
    }
}

watch(
    open,
    (isOpen) => {
        if (isOpen) {
            fetchSummary();
            return;
        }

        abortController?.abort();
    },
    { immediate: true },
);

function fullName(): string {
    if (!summary.value) {
        return '';
    }

    return [summary.value.patient.first_name, summary.value.patient.last_name, summary.value.patient.second_last_name]
        .filter(Boolean)
        .join(' ');
}

function consultationHref(uuid: string): string {
    return show.url(uuid);
}

const createConsultationHref = create.url({ query: { patient: props.patient.uuid } });
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{ patient.first_name }} {{ patient.last_name }}</DialogTitle>
                <DialogDescription>Datos del paciente y sus consultas más recientes.</DialogDescription>
            </DialogHeader>

            <div v-if="isLoading" class="space-y-2">
                <Skeleton class="h-4 w-3/4" />
                <Skeleton class="h-4 w-1/2" />
                <Skeleton class="h-4 w-2/3" />
            </div>

            <p v-else-if="hasFailed" class="text-sm text-muted-foreground">
                No se pudo cargar la información del paciente.
            </p>

            <div v-else-if="summary" class="space-y-4">
                <div class="grid grid-cols-2 gap-3 text-sm">
                    <div>
                        <p class="text-muted-foreground">Nombre completo</p>
                        <p class="font-medium">{{ fullName() }}</p>
                    </div>
                    <div>
                        <p class="text-muted-foreground">Número de inscripción</p>
                        <p class="font-medium">{{ summary.patient.enrollment_number ?? 'Sin inscripción' }}</p>
                    </div>
                    <div>
                        <p class="text-muted-foreground">Fecha de nacimiento</p>
                        <p class="font-medium">{{ summary.patient.birth_date }}</p>
                    </div>
                    <div>
                        <p class="text-muted-foreground">Sexo al nacer</p>
                        <p class="font-medium">
                            {{ SEX_AT_BIRTH_LABELS[summary.patient.sex_at_birth] ?? summary.patient.sex_at_birth }}
                        </p>
                    </div>
                    <div>
                        <p class="text-muted-foreground">Tipo de sangre</p>
                        <p class="font-medium">
                            {{ BLOOD_TYPE_LABELS[summary.patient.blood_type] ?? summary.patient.blood_type }}
                        </p>
                    </div>
                </div>

                <div>
                    <h3 class="mb-2 text-sm font-semibold">Consultas recientes</h3>
                    <p v-if="summary.latest_consultations.length === 0" class="text-sm text-muted-foreground">
                        Este paciente no tiene consultas registradas.
                    </p>
                    <ul v-else class="space-y-2">
                        <li v-for="consultation in summary.latest_consultations" :key="consultation.uuid">
                            <Link
                                :href="consultationHref(consultation.uuid)"
                                class="block rounded-md border p-2 text-sm hover:bg-muted"
                            >
                                <div class="flex items-center justify-between">
                                    <span class="font-medium">{{ consultation.code }}</span>
                                    <span class="text-xs text-muted-foreground">
                                        {{ formatDateTime(consultation.created_at) }}
                                    </span>
                                </div>
                                <p class="text-xs text-muted-foreground">
                                    {{ medicalClassificationLabel(consultation.medical_classification) }}
                                </p>
                            </Link>
                        </li>
                    </ul>
                </div>

                <Button v-if="canCreateConsultation" as-child class="w-full">
                    <Link :href="createConsultationHref">Nueva consulta</Link>
                </Button>
            </div>
        </DialogContent>
    </Dialog>
</template>
