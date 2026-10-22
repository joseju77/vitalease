import { flushPromises, mount, VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { searchPatients } from '@/actions/App/Http/Controllers/DashboardController';
import type { PatientSearchResult } from '@/types/consultations';
import PatientSearch from './PatientSearch.vue';

const ana: PatientSearchResult = {
    uuid: 'patient-uuid-1',
    first_name: 'Ana',
    last_name: 'López',
    second_last_name: 'Pérez',
    enrollment_number: 'A-100',
    is_disabled: false,
};

const luis: PatientSearchResult = {
    uuid: 'patient-uuid-2',
    first_name: 'Luis',
    last_name: 'Gómez',
    second_last_name: null,
    enrollment_number: 'A-200',
    is_disabled: true,
};

const SEARCH_INPUT = 'input[placeholder="Buscar paciente por nombre o número de inscripción..."]';

let wrapper: VueWrapper | null = null;
let fetchMock: ReturnType<typeof vi.fn>;

async function search(results: PatientSearchResult[]): Promise<VueWrapper> {
    fetchMock.mockResolvedValue({ ok: true, json: async () => results });
    wrapper = mount(PatientSearch, { attachTo: document.body });
    await wrapper.get(SEARCH_INPUT).setValue('a');
    await vi.advanceTimersByTimeAsync(50);
    await flushPromises();
    return wrapper;
}

function resultButtons(): HTMLButtonElement[] {
    return Array.from(document.body.querySelectorAll<HTMLButtonElement>('button[type="button"]'));
}

function resultButton(name: string): HTMLButtonElement {
    const button = resultButtons().find((candidate) => candidate.textContent?.includes(name));
    if (!button) {
        throw new Error(`No result "${name}"`);
    }
    return button;
}

describe('PatientSearch.vue', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
    });

    afterEach(() => {
        wrapper?.unmount();
        wrapper = null;
        document.body.innerHTML = '';
        vi.useRealTimers();
        vi.unstubAllGlobals();
    });

    it('queries the patient search endpoint with the typed term', async () => {
        await search([ana]);

        expect(fetchMock.mock.calls[0][0]).toBe(searchPatients.url({ query: { query: 'a' } }));
    });

    it('lists active and disabled patients, marking the disabled one with a "Deshabilitado" badge', async () => {
        await search([ana, luis]);

        expect(resultButton('Ana López Pérez').textContent).not.toContain('Deshabilitado');
        expect(resultButton('Luis Gómez').textContent).toContain('Deshabilitado');
        expect(resultButton('Luis Gómez').textContent).toContain('A-200');
    });

    it('renders a disabled patient as a disabled, non-selectable option', async () => {
        await search([ana, luis]);

        expect(resultButton('Luis Gómez').disabled).toBe(true);
        expect(resultButton('Ana López Pérez').disabled).toBe(false);
    });

    it('emits select with the patient when an active patient is chosen', async () => {
        const search_ = await search([ana, luis]);

        resultButton('Ana López Pérez').click();
        await flushPromises();

        expect(search_.emitted('select')).toEqual([[ana]]);
    });

    it('never emits select for a disabled patient, even when its option is clicked', async () => {
        const search_ = await search([ana, luis]);

        resultButton('Luis Gómez').click();
        await flushPromises();

        expect(search_.emitted('select')).toBeUndefined();
    });

    it('shows an empty message when nothing matches', async () => {
        await search([]);

        expect(document.body.textContent).toContain('No se encontraron pacientes.');
    });
});
