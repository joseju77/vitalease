/* eslint-disable vue/one-component-per-file -- minimal inline test doubles for Inertia's Head and Link. */
import { flushPromises, mount, VueWrapper } from '@vue/test-utils';
import { defineComponent, h } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { patientSummary, searchPatients } from '@/actions/App/Http/Controllers/DashboardController';
import type { ConsultationFlash, PatientSearchResult, PatientSummary } from '@/types/consultations';

/**
 * The dashboard reads `Head`/`usePage` from `@inertiajs/vue3`, and its patient
 * summary dialog renders `Link`. Those three are replaced; the real
 * `PatientSearch`, `SearchResultsField`, and `PatientSummaryDialog` are mounted
 * so the search behavior is exercised through the page.
 */
const { pageState, toastSuccess } = vi.hoisted(() => ({
    pageState: { flash: {} as { consultation?: ConsultationFlash } },
    toastSuccess: vi.fn(),
}));

vi.mock('@inertiajs/vue3', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@inertiajs/vue3')>();

    return {
        ...actual,
        Head: defineComponent({
            name: 'InertiaHead',
            props: { title: { type: String, default: '' } },
            setup: () => () => null,
        }),
        Link: defineComponent({
            props: { href: { type: String, required: true } },
            setup:
                (props, { slots }) =>
                () =>
                    h('a', { href: props.href }, slots.default?.()),
        }),
        usePage: () => pageState,
    };
});

/** The persistent layout is applied by Inertia, not by a direct mount; stubbing it skips its asset imports. */
vi.mock('@/layouts/AppLayout.vue', () => ({ default: {} }));

vi.mock('vue-sonner', () => ({ toast: { success: toastSuccess } }));

import DashboardIndex from './Index.vue';

type PendingFetch = {
    url: string;
    init: RequestInit;
    resolve: (value: { ok: boolean; json: () => Promise<unknown> }) => void;
};

const SEARCH_INPUT = 'input[placeholder="Buscar paciente por nombre o número de inscripción..."]';

const anaResult: PatientSearchResult = {
    uuid: 'patient-uuid-1',
    first_name: 'Ana',
    last_name: 'López',
    second_last_name: 'Pérez',
    enrollment_number: 'A-100',
};

const anaSummary: PatientSummary = {
    patient: {
        uuid: 'patient-uuid-1',
        first_name: 'Ana',
        last_name: 'López',
        second_last_name: 'Pérez',
        birth_date: '1990-05-10',
        sex_at_birth: 2,
        blood_type: 1,
        enrollment_number: 'A-100',
    },
    latest_consultations: [],
};

let pendingFetches: PendingFetch[];
let fetchMock: ReturnType<typeof vi.fn>;
let wrapper: VueWrapper | null = null;

function mountDashboard(can = { searchPatients: true, createConsultation: true }): VueWrapper {
    wrapper = mount(DashboardIndex, {
        props: { latestConsultations: [], can },
        attachTo: document.body,
    });
    return wrapper;
}

function searchRequests(): PendingFetch[] {
    return pendingFetches.filter((request) => request.url.startsWith(searchPatients.url()));
}

async function typeQuery(target: VueWrapper, value: string) {
    await target.get(SEARCH_INPUT).setValue(value);
}

describe('Pages/dashboard/Index.vue', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        pageState.flash = {};
        toastSuccess.mockClear();
        pendingFetches = [];
        fetchMock = vi.fn((url: string, init: RequestInit) => {
            return new Promise((resolve, reject) => {
                pendingFetches.push({ url, init, resolve });
                init.signal?.addEventListener('abort', () => {
                    const abortError = new Error('Aborted');
                    abortError.name = 'AbortError';
                    reject(abortError);
                });
            });
        });
        vi.stubGlobal('fetch', fetchMock);
    });

    afterEach(() => {
        wrapper?.unmount();
        wrapper = null;
        document.body.innerHTML = '';
        vi.useRealTimers();
        vi.unstubAllGlobals();
    });

    it('titles the page Consultas', () => {
        const page = mountDashboard();

        expect(page.get('h1').text()).toBe('Consultas');
        expect(page.findComponent({ name: 'InertiaHead' }).props('title')).toBe('Consultas');
    });

    it('shows the patient search only when the user can search patients', () => {
        expect(mountDashboard().find(SEARCH_INPUT).exists()).toBe(true);
        wrapper?.unmount();

        expect(mountDashboard({ searchPatients: false, createConsultation: true }).find(SEARCH_INPUT).exists()).toBe(
            false,
        );
    });

    it('searches from a single character after the 50 ms debounce with a JSON request', async () => {
        const page = mountDashboard();

        await typeQuery(page, 'a');
        await vi.advanceTimersByTimeAsync(49);
        expect(fetchMock).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(1);
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(pendingFetches[0].url).toBe(searchPatients.url({ query: { query: 'a' } }));
        expect(pendingFetches[0].init.headers).toEqual({ Accept: 'application/json' });
    });

    it('trims the query before searching', async () => {
        const page = mountDashboard();

        await typeQuery(page, '  ana  ');
        await vi.advanceTimersByTimeAsync(50);

        expect(pendingFetches[0].url).toBe(searchPatients.url({ query: { query: 'ana' } }));
    });

    it('does not search for an empty or whitespace-only query', async () => {
        const page = mountDashboard();

        await typeQuery(page, '   ');
        await vi.advanceTimersByTimeAsync(200);
        await typeQuery(page, 'a');
        await typeQuery(page, '');
        await vi.advanceTimersByTimeAsync(200);

        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('aborts a stale in-flight search and only renders the newest results', async () => {
        const page = mountDashboard();

        await typeQuery(page, 'an');
        await vi.advanceTimersByTimeAsync(50);
        const staleSignal = pendingFetches[0].init.signal as AbortSignal;

        await typeQuery(page, 'ana');
        expect(staleSignal.aborted).toBe(true);

        await vi.advanceTimersByTimeAsync(50);
        expect(searchRequests()).toHaveLength(2);
        searchRequests()[1].resolve({ ok: true, json: async () => [anaResult] });
        await flushPromises();

        expect(page.text()).toContain('Ana López Pérez');
        expect(page.text()).toContain('A-100');
        expect(page.text()).not.toContain('Buscando pacientes...');
    });

    it('shows the empty state when the search returns no patients', async () => {
        const page = mountDashboard();

        await typeQuery(page, 'zzz');
        await vi.advanceTimersByTimeAsync(50);
        expect(page.text()).toContain('Buscando pacientes...');

        pendingFetches[0].resolve({ ok: true, json: async () => [] });
        await flushPromises();

        expect(page.text()).toContain('No se encontraron pacientes.');
    });

    it('opens the patient summary dialog for the selected result', async () => {
        const page = mountDashboard();

        await typeQuery(page, 'ana');
        await vi.advanceTimersByTimeAsync(50);
        pendingFetches[0].resolve({ ok: true, json: async () => [anaResult] });
        await flushPromises();

        const resultButton = page.findAll('button').find((button) => button.text().includes('Ana López Pérez'));
        if (!resultButton) {
            throw new Error('No search result button rendered');
        }
        await resultButton.trigger('click');
        await flushPromises();

        const summaryRequest = pendingFetches.find((request) => request.url === patientSummary.url(anaResult.uuid));
        expect(summaryRequest).toBeDefined();
        summaryRequest?.resolve({ ok: true, json: async () => anaSummary });
        await flushPromises();

        const dialog = document.body.querySelector('[role="dialog"]');
        expect(dialog?.textContent).toContain('Datos del paciente y sus consultas más recientes.');
        expect(dialog?.textContent).toContain('Ana López Pérez');
        expect(page.get<HTMLInputElement>(SEARCH_INPUT).element.value).toBe('');
    });

    it.each([
        ['created', 'Consulta CON-0001 registrada'],
        ['updated', 'Consulta CON-0001 actualizada'],
        ['deleted', 'Consulta CON-0001 eliminada'],
    ] as const)('toasts the %s consultation flash', (action, message) => {
        pageState.flash = { consultation: { uuid: 'consultation-uuid-1', code: 'CON-0001', action } };

        mountDashboard();

        expect(toastSuccess).toHaveBeenCalledTimes(1);
        expect(toastSuccess).toHaveBeenCalledWith(message);
    });

    it('does not toast without a consultation flash', () => {
        mountDashboard();

        expect(toastSuccess).not.toHaveBeenCalled();
    });
});
