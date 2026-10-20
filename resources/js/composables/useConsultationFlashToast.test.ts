import { describe, expect, it, vi } from 'vitest';
import type { ConsultationFlash } from '@/types/consultations';

const { pageState, toastSuccess } = vi.hoisted(() => ({
    pageState: { flash: {} as { consultation?: ConsultationFlash } },
    toastSuccess: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => pageState,
}));

vi.mock('vue-sonner', () => ({ toast: { success: toastSuccess } }));

import { useConsultationFlashToast } from './useConsultationFlashToast';

describe('useConsultationFlashToast', () => {
    it.each([
        ['created', 'Consulta CON-0001 registrada'],
        ['updated', 'Consulta CON-0001 actualizada'],
        ['voided', 'Consulta CON-0001 anulada'],
        ['restored', 'Consulta CON-0001 restaurada'],
    ] as const)('toasts the %s consultation flash', (action, message) => {
        toastSuccess.mockClear();
        pageState.flash = { consultation: { uuid: 'consultation-uuid-1', code: 'CON-0001', action } };

        useConsultationFlashToast();

        expect(toastSuccess).toHaveBeenCalledTimes(1);
        expect(toastSuccess).toHaveBeenCalledWith(message);
    });

    it('does not toast without a consultation flash', () => {
        toastSuccess.mockClear();
        pageState.flash = {};

        useConsultationFlashToast();

        expect(toastSuccess).not.toHaveBeenCalled();
    });
});
