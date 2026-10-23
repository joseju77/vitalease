// Medication demand projection report: aggregates a year of dispensations, so its
// cost grows with the consultation volume (needs reports.generate: super-admin).
import { sleep } from 'k6';
import { emailForVu, scenarioOptions, THINK_TIME_SECONDS } from '../lib/config.js';
import { expectOk } from '../lib/checks.js';
import { discoverPools, pick } from '../lib/pools.js';
import { vuSessionFor } from '../lib/session.js';
import { buildSummaryOutputs } from '../lib/summary.js';

export const NAMES = ['reports.demand-index', 'reports.demand-projection'];
export const options = scenarioOptions(NAMES);

export function setup() {
    return discoverPools();
}

export function iteration(pools) {
    const session = vuSessionFor(emailForVu('admin'));

    if (session === null) {
        return;
    }

    expectOk(session.page('/reports/medication-demand', 'reports.demand-index'), 'reports.demand-index');
    expectOk(
        session.page(
            `/reports/medication-demand?medication=${pick(pools.medications).uuid}`,
            'reports.demand-projection',
        ),
        'reports.demand-projection',
    );
}

export default function (pools) {
    iteration(pools);
    sleep(THINK_TIME_SECONDS);
}

export function handleSummary(data) {
    return buildSummaryOutputs(data);
}
