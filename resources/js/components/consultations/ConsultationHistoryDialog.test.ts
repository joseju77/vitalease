import { flushPromises, mount, VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { history } from '@/actions/App/Http/Controllers/MedicalConsultationController';
import { formatDateTime } from '@/lib/formatDateTime';
import type { ConsultationHistoryGroup, ConsultationHistoryPage } from '@/types/consultations';
import ConsultationHistoryDialog from './ConsultationHistoryDialog.vue';

type PendingFetch = { url: string; resolve: (value: unknown) => void };

let wrapper: VueWrapper | null = null;
let pendingFetches: PendingFetch[];
let fetchMock: ReturnType<typeof vi.fn>;

function group(overrides: Partial<ConsultationHistoryGroup> = {}): ConsultationHistoryGroup {
    return {
        id: 'batch-1',
        event: 'updated',
        event_label: 'Modificación',
        occurred_at: '2026-09-02T09:00:00Z',
        actor: { name: 'Dra. Ruiz' },
        reason: null,
        changes: [
            { section: 'consulta', item: null, field: 'Pronóstico', old: 'Bueno', new: 'Reservado' },
            { section: 'consulta', item: null, field: 'Diagnóstico', old: null, new: 'Migraña' },
            { section: 'tratamiento', item: 'Paracetamol Tableta 500 mg', field: 'Cantidad', old: 2, new: 4 },
        ],
        ...overrides,
    };
}

function page(data: ConsultationHistoryGroup[], nextCursor: number | null = null): ConsultationHistoryPage {
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

async function openDialog(): Promise<void> {
    wrapper = mount(ConsultationHistoryDialog, {
        props: { uuid: 'consultation-uuid-9', code: 'CON-0009' },
        attachTo: document.body,
    });
    await wrapper.get('button').trigger('click');
    await flushPromises();
}

describe('ConsultationHistoryDialog.vue', () => {
    beforeEach(() => {
        pendingFetches = [];
        fetchMock = vi.fn(
            (url: string) =>
                new Promise((resolve) => {
                    pendingFetches.push({ url, resolve });
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

    it('does not load anything until the dialog is opened', () => {
        wrapper = mount(ConsultationHistoryDialog, {
            props: { uuid: 'consultation-uuid-9', code: 'CON-0009' },
            attachTo: document.body,
        });

        expect(wrapper.text()).toContain('Historial de cambios');
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('requests the JSON history endpoint on open and shows a loading state', async () => {
        await openDialog();

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(pendingFetches[0].url).toBe(history.url('consultation-uuid-9'));
        expect(fetchMock.mock.calls[0][1].headers).toEqual({ Accept: 'application/json' });
        expect(document.body.querySelector('[data-testid="history-loading"]')).not.toBeNull();
    });

    it('renders each group with event badge, date, user and a changes table', async () => {
        await openDialog();
        respond(0, page([group()]));
        await flushPromises();

        const text = dialog()?.textContent ?? '';
        expect(document.body.querySelector('[data-testid="history-loading"]')).toBeNull();
        expect(text).toContain('Modificación');
        expect(text).toContain(formatDateTime('2026-09-02T09:00:00Z'));
        expect(text).toContain('Dra. Ruiz');

        const headers = Array.from(dialog()?.querySelectorAll('th') ?? []).map((th) => th.textContent?.trim());
        expect(headers).toEqual(['Campo', 'Anterior', 'Nuevo', 'Campo', 'Anterior', 'Nuevo']);

        const rows = Array.from(dialog()?.querySelectorAll('tbody tr') ?? []).map((row) =>
            Array.from(row.querySelectorAll('td')).map((cell) => cell.textContent?.trim()),
        );
        expect(rows).toEqual([
            ['Pronóstico', 'Bueno', 'Reservado'],
            ['Diagnóstico', '—', 'Migraña'],
            ['Paracetamol Tableta 500 mg · Cantidad', '2', '4'],
        ]);
        expect(Array.from(dialog()?.querySelectorAll('h3') ?? []).map((h) => h.textContent?.trim())).toEqual([
            'consulta',
            'tratamiento',
        ]);
    });

    it('shows the reason of void and restore groups without a changes table', async () => {
        await openDialog();
        respond(
            0,
            page([
                group({
                    id: 'batch-2',
                    event: 'voided',
                    event_label: 'Anulación',
                    reason: 'Registro duplicado',
                    changes: [],
                }),
            ]),
        );
        await flushPromises();

        expect(dialog()?.textContent).toContain('Anulación');
        expect(dialog()?.textContent).toContain('Motivo: Registro duplicado');
        expect(dialog()?.querySelector('table')).toBeNull();
    });

    it('shows an empty state when there is no history', async () => {
        await openDialog();
        respond(0, page([]));
        await flushPromises();

        expect(dialog()?.textContent).toContain('Esta consulta no tiene cambios registrados.');
    });

    it('shows an error state when the request fails', async () => {
        await openDialog();
        respond(0, {}, false);
        await flushPromises();

        expect(dialog()?.textContent).toContain('No se pudo cargar el historial de cambios.');
    });

    it('shows an error state when the network fails', async () => {
        fetchMock.mockRejectedValueOnce(new Error('offline'));
        await openDialog();

        expect(dialog()?.textContent).toContain('No se pudo cargar el historial de cambios.');
    });

    it('loads more groups with the cursor and appends them', async () => {
        await openDialog();
        respond(0, page([group({ id: 'batch-1' })], 42));
        await flushPromises();

        dialogButton('Cargar más')?.click();
        await flushPromises();

        expect(pendingFetches[1].url).toBe(history.url('consultation-uuid-9', { query: { cursor: 42 } }));
        expect(pendingFetches[1].url).toContain('cursor=42');

        respond(1, page([group({ id: 'batch-0', event: 'created', event_label: 'Creación' })]));
        await flushPromises();

        expect(dialog()?.querySelectorAll('li > div').length).toBe(2);
        expect(dialog()?.textContent).toContain('Creación');
        expect(dialogButton('Cargar más')).toBeUndefined();
    });

    it('keeps the loaded groups when loading more fails', async () => {
        await openDialog();
        respond(0, page([group()], 42));
        await flushPromises();

        dialogButton('Cargar más')?.click();
        await flushPromises();
        respond(1, {}, false);
        await flushPromises();

        expect(dialog()?.textContent).toContain('Modificación');
        expect(dialog()?.textContent).toContain('No se pudieron cargar más cambios.');
    });
});
