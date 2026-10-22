import { usePage } from '@inertiajs/vue3';
import { watch } from 'vue';
import { toast } from 'vue-sonner';

/**
 * Watches the one-time patient flashes (`patientUpdated`, `patientDisabled`,
 * `patientEnabled`) and renders a success toast for each. `patients.show` is
 * the redirect target of all three mutations and calls this composable once.
 */
export function usePatientFlashToast(): void {
    const page = usePage();

    watch(
        () => page.flash?.patientUpdated,
        (flashed) => {
            if (flashed) {
                toast.success('Paciente actualizado');
            }
        },
        { immediate: true },
    );

    watch(
        () => page.flash?.patientDisabled,
        (flashed) => {
            if (flashed) {
                toast.success('Paciente deshabilitado');
            }
        },
        { immediate: true },
    );

    watch(
        () => page.flash?.patientEnabled,
        (flashed) => {
            if (flashed) {
                toast.success('Paciente habilitado');
            }
        },
        { immediate: true },
    );
}
