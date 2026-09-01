import { describe, expect, it, vi } from 'vitest';
import type { MedicationFlash } from '@/types/inventory';

const { pageState, toastSuccess } = vi.hoisted(() => ({
    pageState: { flash: {} as { medication?: MedicationFlash } },
    toastSuccess: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => pageState,
}));

vi.mock('vue-sonner', () => ({ toast: { success: toastSuccess } }));

import { useMedicationFlashToast } from './useMedicationFlashToast';

describe('useMedicationFlashToast', () => {
    it.each([
        ['created', 'Medicamento Paracetamol creado'],
        ['updated', 'Medicamento Paracetamol actualizado'],
        ['activated', 'Medicamento Paracetamol activado'],
        ['deactivated', 'Medicamento Paracetamol desactivado'],
        ['deleted', 'Medicamento Paracetamol eliminado'],
        ['entry_recorded', 'Entrada registrada para Paracetamol'],
        ['adjustment_recorded', 'Ajuste registrado para Paracetamol'],
    ] as const)('toasts the %s medication flash', (action, message) => {
        toastSuccess.mockClear();
        pageState.flash = { medication: { uuid: 'medication-uuid-1', name: 'Paracetamol', action } };

        useMedicationFlashToast();

        expect(toastSuccess).toHaveBeenCalledTimes(1);
        expect(toastSuccess).toHaveBeenCalledWith(message);
    });

    it('does not toast without a medication flash', () => {
        toastSuccess.mockClear();
        pageState.flash = {};

        useMedicationFlashToast();

        expect(toastSuccess).not.toHaveBeenCalled();
    });
});
