<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Pencil } from '@lucide/vue';
import { index as dashboard } from '@/actions/App/Http/Controllers/DashboardController';
import { edit } from '@/actions/App/Http/Controllers/MedicalConsultationController';
import ConsultationDetail from '@/components/consultations/ConsultationDetail.vue';
import ConsultationPageHeader from '@/components/consultations/ConsultationPageHeader.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDateTime } from '@/lib/formatDateTime';
import type { ConsultationAggregate } from '@/types/consultations';

defineOptions({ layout: (h: any, page: any) => h(AppLayout, { title: 'Detalle de consulta' }, () => page) });
defineProps<{ consultation: ConsultationAggregate }>();
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
                <Button v-if="consultation.can.update" as-child>
                    <Link :href="edit.url(consultation.uuid)"><Pencil aria-hidden="true" />Editar</Link>
                </Button>
            </template>
        </ConsultationPageHeader>
        <div class="mx-auto w-full max-w-5xl">
            <ConsultationDetail :consultation="consultation" />
        </div>
    </div>
</template>
