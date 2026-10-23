import { flushPromises, mount, VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import PatientSearch from './PatientSearch.vue';

const SEARCH_INPUT = 'input[placeholder="Buscar paciente por nombre o número de inscripción..."]';

let wrapper: VueWrapper | null = null;
let fetchMock: ReturnType<typeof vi.fn>;

async function search(degradedHeader: string | null): Promise<VueWrapper> {
    fetchMock.mockResolvedValue({
        ok: true,
        headers: { get: (name: string) => (name === 'X-Search-Degraded' ? degradedHeader : null) },
        json: async () => [],
    });
    wrapper = mount(PatientSearch, { attachTo: document.body });
    await wrapper.get(SEARCH_INPUT).setValue('a');
    await vi.advanceTimersByTimeAsync(50);
    await flushPromises();
    return wrapper;
}

describe('PatientSearch.vue degraded search hint', () => {
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

    it('shows the degraded notice when the response carries X-Search-Degraded: 1', async () => {
        await search('1');

        expect(document.body.querySelector('[data-testid="search-degraded-notice"]')).not.toBeNull();
    });

    it('shows no notice when the header is absent', async () => {
        await search(null);

        expect(document.body.querySelector('[data-testid="search-degraded-notice"]')).toBeNull();
    });

    it('clears the notice on the next healthy search', async () => {
        const first = await search('1');
        fetchMock.mockResolvedValue({ ok: true, headers: { get: () => null }, json: async () => [] });

        await first.get(SEARCH_INPUT).setValue('an');
        await vi.advanceTimersByTimeAsync(50);
        await flushPromises();

        expect(document.body.querySelector('[data-testid="search-degraded-notice"]')).toBeNull();
    });
});
