// Patient detail: the full profile page and the dashboard summary JSON.
import { sleep } from 'k6';
import { emailForVu, scenarioOptions, THINK_TIME_SECONDS } from '../lib/config.js';
import { expectOk } from '../lib/checks.js';
import { discoverPools, pick } from '../lib/pools.js';
import { vuSessionFor } from '../lib/session.js';
import { buildSummaryOutputs } from '../lib/summary.js';

export const NAMES = ['patients.show', 'patients.summary'];
export const options = scenarioOptions(NAMES);

export function setup() {
    return discoverPools();
}

export function iteration(pools) {
    const session = vuSessionFor(emailForVu('clinical'));

    if (session === null) {
        return;
    }

    const patient = pick(pools.patients);

    expectOk(session.page(`/patients/${patient.uuid}`, 'patients.show'), 'patients.show');
    expectOk(session.json(`/dashboard/patients/${patient.uuid}`, 'patients.summary'), 'patients.summary');
}

export default function (pools) {
    iteration(pools);
    sleep(THINK_TIME_SECONDS);
}

export function handleSummary(data) {
    return buildSummaryOutputs(data);
}
