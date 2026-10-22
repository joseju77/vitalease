import { flushPromises, mount, VueWrapper } from '@vue/test-utils';
import { reactive } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { Neighborhood, PatientContactInformation } from '@/types/patients';
import ContactStep from './ContactStep.vue';

/**
 * The edit page mounts `ContactStep` with a stored zip code and the
 * neighborhoods of that zip as `initialNeighborhoods`. These tests cover the
 * prefilled (immediate) lookup; the typing/debounce behavior is covered in
 * `Pages/patients/Register.test.ts`.
 */
type FetchCall = {
    url: string;
    signal: AbortSignal;
    resolve: (value: { ok: boolean; json: () => Promise<unknown> }) => void;
    reject: (reason: unknown) => void;
};

const seeded: Neighborhood[] = [
    { id: 12, name: 'Centro' },
    { id: 13, name: 'Americana' },
];

let pendingFetches: FetchCall[];
let fetchMock: ReturnType<typeof vi.fn>;
let wrapper: VueWrapper | null = null;

function contactInformation(overrides: Partial<PatientContactInformation> = {}): PatientContactInformation {
    return reactive({
        address: 'Calle 1',
        phone_number: '+521234567890',
        personal_email: 'ana@example.test',
        institutional_email: null,
        zip_code: '44100',
        neighborhood_id: 12,
        ...overrides,
    }) as PatientContactInformation;
}

function mountStep(model: PatientContactInformation, initialNeighborhoods?: Neighborhood[]): VueWrapper {
    wrapper = mount(ContactStep, { props: { modelValue: model, initialNeighborhoods } });
    return wrapper;
}

function resolveFetch(index: number, body: unknown, ok = true): void {
    pendingFetches[index].resolve({ ok, json: async () => body });
}

function neighborhoodSelect(step: VueWrapper) {
    return step.get('#contact-neighborhood');
}

describe('ContactStep.vue prefilled zip code (edit page)', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        pendingFetches = [];
        fetchMock = vi.fn((url: string, init?: RequestInit) => {
            return new Promise((resolve, reject) => {
                const signal = init?.signal as AbortSignal;
                pendingFetches.push({ url, signal, resolve, reject });
                signal.addEventListener('abort', () => {
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
        vi.useRealTimers();
        vi.unstubAllGlobals();
    });

    it('looks the prefilled zip code up immediately on mount, without waiting for the typing debounce', () => {
        mountStep(contactInformation(), seeded);

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(pendingFetches[0].url).toContain('zip_code=44100');
    });

    it('keeps the neighborhood select enabled with the seeded neighborhoods while the first lookup is pending', () => {
        const step = mountStep(contactInformation(), seeded);

        expect(neighborhoodSelect(step).attributes('disabled')).toBeUndefined();
    });

    it('keeps the prefilled neighborhood when the lookup response still contains it', async () => {
        const model = contactInformation({ neighborhood_id: 12 });
        mountStep(model, seeded);

        resolveFetch(0, [
            { id: 12, name: 'Centro' },
            { id: 14, name: 'Moderna' },
        ]);
        await flushPromises();

        expect(model.neighborhood_id).toBe(12);
    });

    it('clears the prefilled neighborhood when the lookup response no longer contains it', async () => {
        const model = contactInformation({ neighborhood_id: 12 });
        mountStep(model, seeded);

        resolveFetch(0, [{ id: 14, name: 'Moderna' }]);
        await flushPromises();

        expect(model.neighborhood_id).toBeNull();
    });

    it('keeps the seeded neighborhoods and the stored selection when the first lookup fails', async () => {
        const model = contactInformation({ neighborhood_id: 12 });
        const step = mountStep(model, seeded);

        resolveFetch(0, {}, false);
        await flushPromises();

        expect(model.neighborhood_id).toBe(12);
        expect(neighborhoodSelect(step).attributes('disabled')).toBeUndefined();
        expect(step.text()).not.toContain('No se encontraron colonias para este código postal.');
    });

    it('keeps the seeded neighborhoods and the stored selection when the first lookup hits a network error', async () => {
        const model = contactInformation({ neighborhood_id: 12 });
        const step = mountStep(model, seeded);

        pendingFetches[0].reject(new Error('offline'));
        await flushPromises();

        expect(model.neighborhood_id).toBe(12);
        expect(neighborhoodSelect(step).attributes('disabled')).toBeUndefined();
        expect(step.text()).not.toContain('No se encontraron colonias para este código postal.');
    });

    it('shows the empty state and clears the selection when the first lookup fails without seeded neighborhoods', async () => {
        const model = contactInformation({ neighborhood_id: 12 });
        const step = mountStep(model, []);

        resolveFetch(0, {}, false);
        await flushPromises();

        expect(model.neighborhood_id).toBeNull();
        expect(step.text()).toContain('No se encontraron colonias para este código postal.');
        expect(neighborhoodSelect(step).attributes('disabled')).toBeDefined();
    });

    it('does not look anything up when the prefilled zip code is empty', () => {
        const step = mountStep(contactInformation({ zip_code: null, neighborhood_id: null }), []);

        expect(fetchMock).not.toHaveBeenCalled();
        expect(neighborhoodSelect(step).attributes('disabled')).toBeDefined();
        expect(step.text()).not.toContain('No se encontraron colonias para este código postal.');
    });

    it('does not look anything up when the prefilled zip code is incomplete', () => {
        mountStep(contactInformation({ zip_code: '441', neighborhood_id: null }), []);

        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('debounces later zip changes instead of looking them up immediately', async () => {
        const step = mountStep(contactInformation(), seeded);
        resolveFetch(0, seeded);
        await flushPromises();

        await step.get('input[autocomplete="postal-code"]').setValue('11000');
        expect(fetchMock).toHaveBeenCalledTimes(1);

        await vi.advanceTimersByTimeAsync(300);

        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(pendingFetches[1].url).toContain('zip_code=11000');
    });

    it('clears the stale neighborhood once a changed zip returns different neighborhoods', async () => {
        const model = contactInformation({ neighborhood_id: 12 });
        const step = mountStep(model, seeded);
        resolveFetch(0, seeded);
        await flushPromises();

        await step.get('input[autocomplete="postal-code"]').setValue('11000');
        await vi.advanceTimersByTimeAsync(300);
        resolveFetch(1, [{ id: 90, name: 'Roma Norte' }]);
        await flushPromises();

        expect(model.neighborhood_id).toBeNull();
    });
});
