import { usePage } from '@inertiajs/vue3';
import { watch } from 'vue';
import { toast } from 'vue-sonner';
import { medicationActionMessage } from '@/lib/inventoryLabels';

/**
 * Watches `page.flash?.medication` and renders a success toast for it,
 * mirroring the inline pattern in `Pages/dashboard/Index.vue` for
 * `page.flash?.consultation`. Every inventory page that can receive a
 * medication mutation flash (list, detail) calls this composable once.
 */
export function useMedicationFlashToast(): void {
    const page = usePage();

    watch(
        () => page.flash?.medication,
        (medication) => {
            if (!medication) {
                return;
            }

            toast.success(medicationActionMessage(medication));
        },
        { immediate: true },
    );
}
