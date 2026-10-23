// Experiment 2a: concurrent dispensation of ONE medication with limited stock.
//
// setup() sets the medication's stock to STOCK through the adjustment endpoint.
// Many VUs then create consultations dispensing DISPENSE_QUANTITY units each, far
// more attempts than the stock allows. Expected: accepted * quantity never exceeds
// STOCK, the rest are 422, current_stock never goes negative. Verify afterwards
// with sql/stock-consistency.sql (`make swarm-sql-check`).
//
//   -e RUN_ID=<id> -e STOCK=200 -e DISPENSE_QUANTITY=1 -e VUS=50 -e DURATION=60s
import { sleep } from 'k6';
import {
    DISPENSE_QUANTITY,
    emailForVu,
    reportingThresholds,
    SUMMARY_TREND_STATS,
    THINK_TIME_SECONDS,
} from '../lib/config.js';
import { consultationPayload, dispenseAccepted, dispenseRejected, submitConsultation } from '../lib/consultation.js';
import { prepareLimitedStock } from '../lib/limited-stock.js';
import { pick } from '../lib/pools.js';
import { vuSessionFor } from '../lib/session.js';
import { buildSummaryOutputs } from '../lib/summary.js';

export const options = {
    vus: parseInt(__ENV.VUS || '50', 10),
    duration: __ENV.DURATION || '60s',
    // 422 is expected here; only unexpected statuses fail the run.
    thresholds: {
        ...reportingThresholds(),
        http_req_failed: ['rate<0.01'],
        'http_req_duration{name:concurrency.dispense}': ['max>=0'],
    },
    summaryTrendStats: SUMMARY_TREND_STATS,
};

export function setup() {
    return prepareLimitedStock();
}

export default function (data) {
    const session = vuSessionFor(emailForVu('clinical'));

    if (session === null) {
        return;
    }

    const payload = consultationPayload(pick(data.patients).uuid, data.limited.uuid, DISPENSE_QUANTITY);

    submitConsultation(session, payload, 'concurrency.dispense', dispenseAccepted, dispenseRejected);
    sleep(THINK_TIME_SECONDS / 10);
}

export function handleSummary(data) {
    return buildSummaryOutputs(data);
}
