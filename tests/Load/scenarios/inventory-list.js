// Inventory: the medication list, a text search and the low-stock filter
// (needs inventory permission, so it signs in as the super-admin).
import { sleep } from 'k6';
import { emailForVu, scenarioOptions, THINK_TIME_SECONDS } from '../lib/config.js';
import { expectOk } from '../lib/checks.js';
import { discoverPools, pick } from '../lib/pools.js';
import { vuSessionFor } from '../lib/session.js';
import { buildSummaryOutputs } from '../lib/summary.js';

export const NAMES = ['inventory.index', 'inventory.search', 'inventory.low-stock'];
export const options = scenarioOptions(NAMES);

export function setup() {
    return discoverPools();
}

export function iteration(pools) {
    const session = vuSessionFor(emailForVu('admin'));

    if (session === null) {
        return;
    }

    const term = pick(pools.medications).name.slice(0, 4);

    expectOk(session.page('/inventory', 'inventory.index'), 'inventory.index');
    expectOk(session.page(`/inventory?q=${encodeURIComponent(term)}`, 'inventory.search'), 'inventory.search');
    expectOk(session.page('/inventory?low_stock=1', 'inventory.low-stock'), 'inventory.low-stock');
}

export default function (pools) {
    iteration(pools);
    sleep(THINK_TIME_SECONDS);
}

export function handleSummary(data) {
    return buildSummaryOutputs(data);
}
