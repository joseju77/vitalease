// Patient search through the Scout/Meilisearch path: the patients index page and
// the dashboard JSON search used by the consultation patient picker.
import { sleep } from 'k6';
import { emailForVu, scenarioOptions, THINK_TIME_SECONDS } from '../lib/config.js';
import { expectOk } from '../lib/checks.js';
import { discoverPools, pick } from '../lib/pools.js';
import { vuSessionFor } from '../lib/session.js';
import { buildSummaryOutputs } from '../lib/summary.js';

export const NAMES = ['patients.search', 'patients.search-json'];
export const options = scenarioOptions(NAMES);

export function setup() {
    return discoverPools();
}

export function iteration(pools) {
    const session = vuSessionFor(emailForVu('clinical'));

    if (session === null) {
        return;
    }

    const term = pick(pools.terms);

    expectOk(session.page(`/patients?q=${encodeURIComponent(term)}`, 'patients.search'), 'patients.search');
    expectOk(
        session.json(`/dashboard/patients/search?query=${encodeURIComponent(term)}`, 'patients.search-json'),
        'patients.search-json',
    );
}

export default function (pools) {
    iteration(pools);
    sleep(THINK_TIME_SECONDS);
}

export function handleSummary(data) {
    return buildSummaryOutputs(data);
}
