import { usePage } from '@inertiajs/vue3';
import { watch } from 'vue';
import { toast } from 'vue-sonner';

const CONSULTATION_ACTION_LABELS: Record<string, string> = {
    created: 'registrada',
    updated: 'actualizada',
    voided: 'anulada',
    restored: 'restaurada',
};

/**
 * Watches `page.flash?.consultation` and renders a success toast for it.
 * Every consultation page that a mutation redirects to (dashboard after
 * create/update, detail after void/restore) calls this composable once.
 */
export function useConsultationFlashToast(): void {
    const page = usePage();

    watch(
        () => page.flash?.consultation,
        (consultation) => {
            if (!consultation) {
                return;
            }

            const actionLabel = CONSULTATION_ACTION_LABELS[consultation.action] ?? 'registrada';
            toast.success(`Consulta ${consultation.code} ${actionLabel}`);
        },
        { immediate: true },
    );
}
