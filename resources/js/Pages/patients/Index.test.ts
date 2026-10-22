/* eslint-disable vue/one-component-per-file -- minimal inline test doubles for Inertia's Head and Link. */
import { router } from '@inertiajs/core';
import { mount, VueWrapper } from '@vue/test-utils';
import { defineComponent, h } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { Paginator } from '@/types/inventory';
import type { PatientIndexFilters, PatientIndexRow } from '@/types/patients';

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
    };
});

/** The persistent layout is applied by Inertia, not by a direct mount; stubbing it skips its asset imports. */
vi.mock('@/layouts/AppLayout.vue', () => ({ default: {} }));

import Index from './Index.vue';

let wrapper: VueWrapper | null = null;
let getSpy: ReturnType<typeof vi.spyOn>;

function row(overrides: Partial<PatientIndexRow> = {}): PatientIndexRow {
    return {
        uuid: 'patient-uuid-1',
        full_name: 'Ana López Pérez',
        enrollment: 'IMSS',
        enrollment_number: 'A-100',
        is_disabled: false,
        ...overrides,
    };
}

function mountIndex(
    rows: PatientIndexRow[],
    filters: PatientIndexFilters = { q: null, status: 'active' },
    links: Paginator<PatientIndexRow>['links'] = [],
): VueWrapper {
    wrapper = mount(Index, { props: { patients: { data: rows, links }, filters } });
    return wrapper;
}

describe('Pages/patients/Index.vue', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        getSpy = vi.spyOn(router, 'get').mockImplementation(() => {});
    });

    afterEach(() => {
        wrapper?.unmount();
        wrapper = null;
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    it('lists each patient name as a link to the patient detail page', () => {
        const page = mountIndex([row(), row({ uuid: 'patient-uuid-2', full_name: 'Luis Gómez' })]);

        const links = page.findAll('tbody a');
        expect(links.map((link) => link.text())).toEqual(['Ana López Pérez', 'Luis Gómez']);
        expect(links.map((link) => link.attributes('href'))).toEqual([
            '/patients/patient-uuid-1',
            '/patients/patient-uuid-2',
        ]);
    });

    it('shows only identifying columns, never clinical or sensitive data', () => {
        const page = mountIndex([row()]);

        expect(page.findAll('th').map((th) => th.text())).toEqual([
            'Nombre',
            'Inscripción',
            'Número de inscripción',
            'Estado',
        ]);
        expect(page.get('tbody').text()).toContain('IMSS');
        expect(page.get('tbody').text()).toContain('A-100');
    });

    it('marks disabled patients with a "Deshabilitado" badge and leaves active ones unmarked', () => {
        const page = mountIndex([row(), row({ uuid: 'patient-uuid-2', full_name: 'Luis Gómez', is_disabled: true })]);

        const [active, disabled] = page.findAll('tbody tr');
        expect(active.text()).not.toContain('Deshabilitado');
        expect(disabled.text()).toContain('Deshabilitado');
    });

    it('shows a placeholder for a missing enrollment and an empty state without patients', () => {
        const withoutEnrollment = mountIndex([row({ enrollment: null, enrollment_number: null })]);
        expect(withoutEnrollment.findAll('tbody td').map((cell) => cell.text())).toEqual([
            'Ana López Pérez',
            '—',
            '—',
            '',
        ]);
        withoutEnrollment.unmount();

        const empty = mountIndex([]);
        expect(empty.text()).toContain('No se encontraron pacientes.');
    });

    it('prefills the search box and the status filter from the server filters', () => {
        const page = mountIndex([], { q: 'ana', status: 'disabled' });

        expect(page.get<HTMLInputElement>('input[aria-label="Buscar pacientes"]').element.value).toBe('ana');
        expect(page.get<HTMLSelectElement>('select[aria-label="Estado del paciente"]').element.value).toBe('disabled');
    });

    it('offers the Activos, Deshabilitados and Todos status options', () => {
        const page = mountIndex([]);

        expect(page.findAll('option').map((option) => option.text())).toEqual(['Activos', 'Deshabilitados', 'Todos']);
    });

    it('searches 300ms after typing, sending the trimmed term and omitting the default status', async () => {
        const page = mountIndex([]);

        await page.get('input[aria-label="Buscar pacientes"]').setValue('  ana  ');
        expect(getSpy).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(300);

        expect(getSpy).toHaveBeenCalledTimes(1);
        const [url, query, options] = getSpy.mock.calls[0] as [string, Record<string, string>, Record<string, unknown>];
        expect(url).toBe('/patients');
        expect(query).toEqual({ q: 'ana' });
        expect(options).toMatchObject({ preserveState: true, replace: true });
    });

    it('sends only the latest term when typing continues within the debounce window', async () => {
        const page = mountIndex([]);

        await page.get('input[aria-label="Buscar pacientes"]').setValue('an');
        await vi.advanceTimersByTimeAsync(200);
        await page.get('input[aria-label="Buscar pacientes"]').setValue('ana');
        await vi.advanceTimersByTimeAsync(300);

        expect(getSpy).toHaveBeenCalledTimes(1);
        expect((getSpy.mock.calls[0] as unknown[])[1]).toEqual({ q: 'ana' });
    });

    it('filters by status, keeping the current search term', async () => {
        const page = mountIndex([], { q: 'ana', status: 'active' });

        await page.get('select[aria-label="Estado del paciente"]').setValue('all');
        await vi.advanceTimersByTimeAsync(300);

        expect((getSpy.mock.calls[0] as unknown[])[1]).toEqual({ q: 'ana', status: 'all' });
    });

    it('drops both parameters when the search is cleared and the status is back to the default', async () => {
        const page = mountIndex([], { q: 'ana', status: 'disabled' });

        await page.get('input[aria-label="Buscar pacientes"]').setValue('');
        await page.get('select[aria-label="Estado del paciente"]').setValue('active');
        await vi.advanceTimersByTimeAsync(300);

        expect((getSpy.mock.calls.at(-1) as unknown[])[1]).toEqual({});
    });

    it('does not search when unmounted before the debounce elapses', async () => {
        const page = mountIndex([]);

        await page.get('input[aria-label="Buscar pacientes"]').setValue('ana');
        page.unmount();
        wrapper = null;
        await vi.advanceTimersByTimeAsync(300);

        expect(getSpy).not.toHaveBeenCalled();
    });

    it('renders pagination links, marking the active page and disabling links without URL', () => {
        const page = mountIndex([row()], { q: null, status: 'active' }, [
            { url: null, label: '&laquo; Anterior', active: false },
            { url: '/patients?page=1', label: '1', active: true },
            { url: '/patients?page=2', label: '2', active: false },
            { url: '/patients?page=2', label: 'Siguiente &raquo;', active: false },
        ]);

        const nav = page.get('nav');
        const anchors = nav.findAll('a');
        expect(anchors.map((anchor) => anchor.attributes('href'))).toEqual([
            '/patients?page=1',
            '/patients?page=2',
            '/patients?page=2',
        ]);
        expect(anchors[0].classes()).toContain('bg-muted');
        expect(anchors[1].classes()).not.toContain('bg-muted');
        expect(nav.text()).toContain('« Anterior');
    });

    it('hides pagination when there is a single page', () => {
        const page = mountIndex([row()], { q: null, status: 'active' }, [
            { url: null, label: '&laquo; Anterior', active: false },
            { url: '/patients?page=1', label: '1', active: true },
            { url: null, label: 'Siguiente &raquo;', active: false },
        ]);

        expect(page.find('nav').exists()).toBe(false);
    });
});
