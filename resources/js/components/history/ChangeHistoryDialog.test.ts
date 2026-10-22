import { flushPromises, mount, VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { history as patientHistory } from '@/actions/App/Http/Controllers/PatientController';
import { formatDateTime } from '@/lib/formatDateTime';
import type { HistoryGroup, HistoryPage, PatientHistoryGroup } from '@/types/history';
import ConsultationHistoryDialog from '@/components/consultations/ConsultationHistoryDialog.vue';
import ChangeHistoryDialog from './ChangeHistoryDialog.vue';

type PendingFetch = { url: string; signal: AbortSignal; resolve: (value: unknown) => void };

let wrapper: VueWrapper | null = null;
let pendingFetches: PendingFetch[];
let fetchMock: ReturnType<typeof vi.fn>;

function patientGroup(overrides: Partial<PatientHistoryGroup> = {}): PatientHistoryGroup {
    return {
        id: 'batch-1',
        event: 'updated',
        event_label: 'Modificación',
        occurred_at: '2026-09-02T09:00:00Z',
        actor: { name: 'Dra. Ruiz' },
        reason: null,
        changes: [
            { section: 'datos personales', item: null, field: 'Estado civil', old: 'Soltero(a)', new: 'Casado(a)' },
            { section: 'contacto', item: null, field: 'Colonia', old: 'Centro (CP 44100)', new: null },
            { section: 'padecimientos', item: 'Diabetes', field: 'Notas', old: null, new: 'Dieta' },
            { section: 'historial ginecológico', item: null, field: 'Cólicos', old: false, new: true },
        ],
        ...overrides,
    };
}

function page(data: HistoryGroup[], nextCursor: number | null = null): HistoryPage {
    return { data, next_cursor: nextCursor };
}

function respond(index: number, body: unknown, ok = true): void {
    pendingFetches[index].resolve({ ok, json: async () => body });
}

function dialog(): HTMLElement | null {
    return document.body.querySelector<HTMLElement>('[role="dialog"]');
}

function dialogButton(text: string): HTMLButtonElement | undefined {
    return Array.from(dialog()?.querySelectorAll('button') ?? []).find((button) => button.textContent?.trim() === text);
}

function mountPatientHistory(): VueWrapper {
    wrapper = mount(ChangeHistoryDialog, {
        props: {
            buildUrl: (cursor: number | null) =>
                patientHistory.url('patient-uuid-9', cursor === null ? undefined : { query: { cursor } }),
            description: 'Registro de cambios de Ana López.',
            emptyMessage: 'Este paciente no tiene cambios registrados.',
            triggerTestId: 'patient-history',
        },
        attachTo: document.body,
    });
    return wrapper;
}

async function openPatientHistory(): Promise<void> {
    await mountPatientHistory().get('[data-testid="patient-history"]').trigger('click');
    await flushPromises();
}

describe('ChangeHistoryDialog.vue (patient subject)', () => {
    beforeEach(() => {
        pendingFetches = [];
        fetchMock = vi.fn(
            (url: string, init: RequestInit) =>
                new Promise((resolve) => {
                    pendingFetches.push({ url, signal: init.signal as AbortSignal, resolve });
                }),
        );
        vi.stubGlobal('fetch', fetchMock);
    });

    afterEach(() => {
        wrapper?.unmount();
        wrapper = null;
        document.body.innerHTML = '';
        vi.unstubAllGlobals();
    });

    it('exposes the trigger test id and loads nothing until the dialog is opened', () => {
        mountPatientHistory();

        expect(wrapper?.get('[data-testid="patient-history"]').text()).toContain('Historial de cambios');
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('requests the patient history endpoint as JSON on open, showing the description and a loading state', async () => {
        await openPatientHistory();

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(pendingFetches[0].url).toBe('/patients/patient-uuid-9/history');
        expect(fetchMock.mock.calls[0][1].headers).toEqual({ Accept: 'application/json' });
        expect(document.body.querySelector('[data-testid="history-loading"]')).not.toBeNull();
        expect(dialog()?.textContent).toContain('Registro de cambios de Ana López.');
    });

    it('groups the changes of a save by section, labelling items and formatting empty and boolean values', async () => {
        await openPatientHistory();
        respond(0, page([patientGroup()]));
        await flushPromises();

        const text = dialog()?.textContent ?? '';
        expect(text).toContain('Modificación');
        expect(text).toContain(formatDateTime('2026-09-02T09:00:00Z'));
        expect(text).toContain('Dra. Ruiz');
        expect(Array.from(dialog()?.querySelectorAll('h3') ?? []).map((h) => h.textContent?.trim())).toEqual([
            'datos personales',
            'contacto',
            'padecimientos',
            'historial ginecológico',
        ]);

        const rows = Array.from(dialog()?.querySelectorAll('tbody tr') ?? []).map((row) =>
            Array.from(row.querySelectorAll('td')).map((cell) => cell.textContent?.trim()),
        );
        expect(rows).toEqual([
            ['Estado civil', 'Soltero(a)', 'Casado(a)'],
            ['Colonia', 'Centro (CP 44100)', '—'],
            ['Diabetes · Notas', '—', 'Dieta'],
            ['Cólicos', 'No', 'Sí'],
        ]);
    });

    it('shows a registration without actor (self-registration) without a user name', async () => {
        await openPatientHistory();
        respond(0, page([patientGroup({ event: 'created', event_label: 'Registro', actor: null, changes: [] })]));
        await flushPromises();

        expect(dialog()?.textContent).toContain('Registro');
        expect(dialog()?.textContent).not.toContain('Dra. Ruiz');
    });

    it('shows the reason of disable and enable groups without a changes table', async () => {
        await openPatientHistory();
        respond(
            0,
            page([
                patientGroup({
                    id: 'batch-2',
                    event: 'disabled',
                    event_label: 'Deshabilitación',
                    reason: 'Baja voluntaria',
                    changes: [],
                }),
                patientGroup({
                    id: 'batch-3',
                    event: 'enabled',
                    event_label: 'Habilitación',
                    reason: 'Regresó al programa',
                    changes: [],
                }),
            ]),
        );
        await flushPromises();

        expect(dialog()?.textContent).toContain('Deshabilitación');
        expect(dialog()?.textContent).toContain('Motivo: Baja voluntaria');
        expect(dialog()?.textContent).toContain('Habilitación');
        expect(dialog()?.textContent).toContain('Motivo: Regresó al programa');
        expect(dialog()?.querySelector('table')).toBeNull();
    });

    it('shows the subject empty message when there is no history', async () => {
        await openPatientHistory();
        respond(0, page([]));
        await flushPromises();

        expect(dialog()?.textContent).toContain('Este paciente no tiene cambios registrados.');
        expect(document.body.querySelector('[data-testid="history-loading"]')).toBeNull();
    });

    it('shows an error state when the request fails and no empty message', async () => {
        await openPatientHistory();
        respond(0, {}, false);
        await flushPromises();

        expect(dialog()?.textContent).toContain('No se pudo cargar el historial de cambios.');
        expect(dialog()?.textContent).not.toContain('Este paciente no tiene cambios registrados.');
    });

    it('shows an error state when the network fails', async () => {
        fetchMock.mockRejectedValueOnce(new Error('offline'));
        await openPatientHistory();

        expect(dialog()?.textContent).toContain('No se pudo cargar el historial de cambios.');
    });

    it('loads the next page with the cursor, appends it and removes the button on the last page', async () => {
        await openPatientHistory();
        respond(0, page([patientGroup({ id: 'batch-1' })], 42));
        await flushPromises();

        dialogButton('Cargar más')?.click();
        await flushPromises();

        expect(pendingFetches[1].url).toBe('/patients/patient-uuid-9/history?cursor=42');

        respond(1, page([patientGroup({ id: 'batch-0', event: 'created', event_label: 'Registro', changes: [] })]));
        await flushPromises();

        expect(dialog()?.querySelectorAll('li > div').length).toBe(2);
        expect(dialog()?.textContent).toContain('Registro');
        expect(dialogButton('Cargar más')).toBeUndefined();
    });

    it('keeps the loaded groups and reports the failure when loading more fails', async () => {
        await openPatientHistory();
        respond(0, page([patientGroup()], 42));
        await flushPromises();

        dialogButton('Cargar más')?.click();
        await flushPromises();
        respond(1, {}, false);
        await flushPromises();

        expect(dialog()?.textContent).toContain('Modificación');
        expect(dialog()?.textContent).toContain('No se pudieron cargar más cambios.');
    });

    it('aborts the in-flight request when the dialog is closed and reloads from the first page on reopen', async () => {
        await openPatientHistory();
        const firstSignal = pendingFetches[0].signal;

        document.body.querySelector<HTMLElement>('[data-slot="dialog-close"]')?.click();
        await flushPromises();

        expect(firstSignal.aborted).toBe(true);

        await wrapper?.get('[data-testid="patient-history"]').trigger('click');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(pendingFetches[1].url).toBe('/patients/patient-uuid-9/history');
    });
});

describe('ChangeHistoryDialog.vue (consultation subject)', () => {
    beforeEach(() => {
        pendingFetches = [];
        fetchMock = vi.fn(
            (url: string, init: RequestInit) =>
                new Promise((resolve) => {
                    pendingFetches.push({ url, signal: init.signal as AbortSignal, resolve });
                }),
        );
        vi.stubGlobal('fetch', fetchMock);
    });

    afterEach(() => {
        wrapper?.unmount();
        wrapper = null;
        document.body.innerHTML = '';
        vi.unstubAllGlobals();
    });

    it('serves the consultation history through the wrapper with the consultation endpoint and copy', async () => {
        wrapper = mount(ConsultationHistoryDialog, {
            props: { uuid: 'consultation-uuid-9', code: 'CON-0009' },
            attachTo: document.body,
        });
        await wrapper.get('button').trigger('click');
        await flushPromises();

        expect(pendingFetches[0].url).toBe('/consultations/consultation-uuid-9/history');
        expect(dialog()?.textContent).toContain('CON-0009');

        respond(0, page([]));
        await flushPromises();

        expect(dialog()?.textContent).toContain('Esta consulta no tiene cambios registrados.');
    });

    it('renders void and restore events of a consultation with their reason', async () => {
        wrapper = mount(ConsultationHistoryDialog, {
            props: { uuid: 'consultation-uuid-9', code: 'CON-0009' },
            attachTo: document.body,
        });
        await wrapper.get('button').trigger('click');
        await flushPromises();

        respond(
            0,
            page([
                {
                    id: 'batch-5',
                    event: 'voided',
                    event_label: 'Anulación',
                    occurred_at: '2026-09-02T09:00:00Z',
                    actor: { name: 'Dra. Ruiz' },
                    reason: 'Registro duplicado',
                    changes: [],
                },
            ]),
        );
        await flushPromises();

        expect(dialog()?.textContent).toContain('Anulación');
        expect(dialog()?.textContent).toContain('Motivo: Registro duplicado');
    });
});
