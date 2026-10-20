<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Pencil } from '@lucide/vue';
import { index as dashboard } from '@/actions/App/Http/Controllers/DashboardController';
import { edit } from '@/actions/App/Http/Controllers/MedicalConsultationController';
import ConsultationHistoryDialog from '@/components/consultations/ConsultationHistoryDialog.vue';
import ConsultationDetail from '@/components/consultations/ConsultationDetail.vue';
import ConsultationPageHeader from '@/components/consultations/ConsultationPageHeader.vue';
import PatientProfileCard from '@/components/consultations/PatientProfileCard.vue';
import RestoreConsultationDialog from '@/components/consultations/RestoreConsultationDialog.vue';
import VoidConsultationDialog from '@/components/consultations/VoidConsultationDialog.vue';
import VoidedBanner from '@/components/consultations/VoidedBanner.vue';
import { Button } from '@/components/ui/button';
import { useConsultationFlashToast } from '@/composables/useConsultationFlashToast';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDateTime } from '@/lib/formatDateTime';
import type { ConsultationAggregate, PatientProfile } from '@/types/consultations';

defineOptions({ layout: (h: any, page: any) => h(AppLayout, { title: 'Detalle de consulta' }, () => page) });
defineProps<{ consultation: ConsultationAggregate; patientProfile: PatientProfile }>();

useConsultationFlashToast();
</script>

<template>
    <Head :title="consultation.code" />
    <div class="flex flex-1 flex-col gap-6">
        <ConsultationPageHeader :title="`Consulta ${consultation.code}`" :patient="consultation.patient">
            <template #meta>
                <p class="text-sm text-muted-foreground">
                    {{ formatDateTime(consultation.created_at) }} · {{ consultation.physician.name }}
                </p>
            </template>
            <template #actions>
                <Button variant="outline" as-child><Link :href="dashboard.url()">Volver al panel</Link></Button>
                <Button v-if="consultation.can.update && !consultation.voided_at" as-child>
                    <Link :href="edit.url(consultation.uuid)"><Pencil aria-hidden="true" />Editar</Link>
                </Button>
                <VoidConsultationDialog
                    v-if="consultation.can.void && !consultation.voided_at"
                    :uuid="consultation.uuid"
                    :code="consultation.code"
                />
                <RestoreConsultationDialog
                    v-if="consultation.can.restore && consultation.voided_at"
                    :uuid="consultation.uuid"
                    :code="consultation.code"
                />
                <ConsultationHistoryDialog
                    v-if="consultation.can.audit"
                    :uuid="consultation.uuid"
                    :code="consultation.code"
                />
            </template>
        </ConsultationPageHeader>
        <div class="mx-auto flex w-full max-w-5xl flex-col gap-6">
            <VoidedBanner
                v-if="consultation.voided_at"
                :voided-at="consultation.voided_at"
                :voided-by="consultation.voided_by?.name ?? null"
                :reason="consultation.void_reason"
            />
            <PatientProfileCard :profile="patientProfile" />
            <ConsultationDetail :consultation="consultation" />
        </div>
    </div>
</template>
