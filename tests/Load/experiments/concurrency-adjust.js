// Experiment 2b: stock adjustments hammering the same medication that consultations
// are dispensing. Adjustments take StockLedger::lock (row lock) without the
// day-wide advisory lock consultation codes use, so this exercises the row lock
// against concurrent dispensations and against other adjustments.
//
// Two parallel scenarios on ONE medication: DISPENSE_VUS create consultations,
// ADJUST_VUS post +/-1 adjustments (negative ones are rejected with 422 once the
// stock hits zero). The SQL check verifies the ledger arithmetic afterwards.
//
//   -e RUN_ID=<id> -e STOCK=200 -e DISPENSE_VUS=30 -e ADJUST_VUS=30 -e DURATION=60s
import { sleep } from 'k6';
import {
    DISPENSE_QUANTITY,
    emailForVu,
    reportingThresholds,
    SUMMARY_TREND_STATS,
    THINK_TIME_SECONDS,
} from '../lib/config.js';
import {
    consultationPayload,
    dispenseAccepted,
    dispenseRejected,
    submitAdjustment,
    submitConsultation,
} from '../lib/consultation.js';
import { prepareLimitedStock, RUN_ID } from '../lib/limited-stock.js';
import { pick } from '../lib/pools.js';
import { vuSessionFor } from '../lib/session.js';
import { buildSummaryOutputs } from '../lib/summary.js';

const duration = __ENV.DURATION || '60s';

export const options = {
    scenarios: {
        dispensers: {
            executor: 'constant-vus',
            exec: 'dispense',
            vus: parseInt(__ENV.DISPENSE_VUS || '30', 10),
            duration,
        },
        adjusters: { executor: 'constant-vus', exec: 'adjust', vus: parseInt(__ENV.ADJUST_VUS || '30', 10), duration },
    },
    thresholds: {
        ...reportingThresholds(),
        http_req_failed: ['rate<0.01'],
        'http_req_duration{name:concurrency.dispense}': ['max>=0'],
        'http_req_duration{name:concurrency.adjust}': ['max>=0'],
    },
    summaryTrendStats: SUMMARY_TREND_STATS,
};

export function setup() {
    return prepareLimitedStock();
}

export function dispense(data) {
    const session = vuSessionFor(emailForVu('clinical'));

    if (session === null) {
        return;
    }

    const payload = consultationPayload(pick(data.patients).uuid, data.limited.uuid, DISPENSE_QUANTITY);

    submitConsultation(session, payload, 'concurrency.dispense', dispenseAccepted, dispenseRejected);
    sleep(THINK_TIME_SECONDS / 10);
}

export function adjust(data) {
    const session = vuSessionFor(emailForVu('admin'));

    if (session === null) {
        return;
    }

    const quantity = Math.random() < 0.5 ? 1 : -1;

    submitAdjustment(session, data.limited.uuid, quantity, `k6 adjust ${RUN_ID}`, 'concurrency.adjust');
    sleep(THINK_TIME_SECONDS / 10);
}

export function handleSummary(data) {
    return buildSummaryOutputs(data);
}
