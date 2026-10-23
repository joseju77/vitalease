// Consultation detail page (aggregate with vital signs, treatment and regulation).
import { sleep } from 'k6';
import { emailForVu, scenarioOptions, THINK_TIME_SECONDS } from '../lib/config.js';
import { expectOk } from '../lib/checks.js';
import { discoverPools, pick } from '../lib/pools.js';
import { vuSessionFor } from '../lib/session.js';
import { buildSummaryOutputs } from '../lib/summary.js';

export const NAMES = ['consultations.show'];
export const options = scenarioOptions(NAMES);

export function setup() {
    return discoverPools();
}

export function iteration(pools) {
    const session = vuSessionFor(emailForVu('clinical'));

    if (session === null || pools.consultations.length === 0) {
        return;
    }

    expectOk(session.page(`/consultations/${pick(pools.consultations)}`, 'consultations.show'), 'consultations.show');
}

export default function (pools) {
    iteration(pools);
    sleep(THINK_TIME_SECONDS);
}

export function handleSummary(data) {
    return buildSummaryOutputs(data);
}
