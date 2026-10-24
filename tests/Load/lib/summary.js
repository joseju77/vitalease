import { DISPENSE_QUANTITY, STOCK, SUMMARY_TREND_STATS } from './config.js';

export { SUMMARY_TREND_STATS };

const SUMMARY_FILE = __ENV.SUMMARY_FILE || '/results/k6-summary.json';
const VARS_FILE = __ENV.SQL_VARS_FILE || '/results/sql-check-vars.txt';

function round(value) {
    return typeof value === 'number' ? Math.round(value * 100) / 100 : value;
}

function latency(metric) {
    const values = metric ? metric.values : {};

    return {
        p50: round(values.med),
        p90: round(values['p(90)']),
        p95: round(values['p(95)']),
        p99: round(values['p(99)']),
        avg: round(values.avg),
        max: round(values.max),
    };
}

/** Compact, traceable digest: the numbers the evaluation reads, derived from the raw data. */
export function digest(data) {
    const metrics = data.metrics;
    const byName = {};

    for (const key of Object.keys(metrics)) {
        const match = /^http_req_duration\{name:(.+)\}$/.exec(key);

        if (match) {
            byName[match[1]] = latency(metrics[key]);
        }
    }

    // Measured operations only (kind:op): login and setup() requests are excluded.
    // The login scenario has no operations, so it reports its auth requests instead.
    const kind = metrics['http_reqs{kind:op}'] && metrics['http_reqs{kind:op}'].values.count > 0 ? 'op' : 'auth';
    const opDuration = metrics[`http_req_duration{kind:${kind}}`] || metrics.http_req_duration;
    const opRequests = (metrics[`http_reqs{kind:${kind}}`] || metrics.http_reqs || { values: {} }).values;
    const opFailed = metrics[`http_req_failed{kind:${kind}}`] || metrics.http_req_failed;

    return {
        generated_at: new Date().toISOString(),
        measured_kind: kind,
        iterations: metrics.iterations ? metrics.iterations.values.count : 0,
        requests: opRequests.count || 0,
        requests_per_second: round(opRequests.rate || 0),
        error_rate: round(opFailed ? opFailed.values.rate : 0),
        latency_ms: latency(opDuration),
        latency_ms_by_name: byName,
        login_ms: metrics.login_duration ? latency(metrics.login_duration) : null,
        counters: Object.fromEntries(
            Object.keys(metrics)
                .filter((key) =>
                    [
                        'dispense_accepted',
                        'dispense_rejected',
                        'adjust_accepted',
                        'adjust_rejected',
                        'adjust_added_units',
                        'adjust_removed_units',
                        'create_accepted',
                        'create_rejected',
                        'login_failures',
                    ].includes(key),
                )
                .map((key) => [key, metrics[key].values.count]),
        ),
    };
}

function counter(data, name) {
    return data.metrics[name] ? data.metrics[name].values.count : 0;
}

function textSummary(data) {
    const summary = digest(data);
    const lines = [
        '',
        `iterations: ${summary.iterations}   requests: ${summary.requests} (${summary.requests_per_second}/s)   error rate: ${summary.error_rate}`,
        `latency ms: p50=${summary.latency_ms.p50} p90=${summary.latency_ms.p90} p95=${summary.latency_ms.p95} p99=${summary.latency_ms.p99}`,
    ];

    for (const [name, values] of Object.entries(summary.latency_ms_by_name)) {
        lines.push(`  ${name}: p50=${values.p50} p95=${values.p95} p99=${values.p99}`);
    }

    lines.push(`counters: ${JSON.stringify(summary.counters)}`, '');

    return lines.join('\n');
}

/**
 * handleSummary for every script: raw data plus digest as JSON, a readable
 * stdout summary and, for the concurrency experiments, the psql variables the
 * SQL invariant check needs.
 */
export function buildSummaryOutputs(data) {
    const outputs = {
        [SUMMARY_FILE]: JSON.stringify({ digest: digest(data), raw: data }, null, 2),
        stdout: textSummary(data),
    };

    const concurrencyTotal = ['dispense_accepted', 'dispense_rejected', 'adjust_accepted', 'adjust_rejected'].reduce(
        (sum, name) => sum + counter(data, name),
        0,
    );

    // Only the concurrency experiments produce these counters.
    if (__ENV.RUN_ID && concurrencyTotal > 0) {
        outputs[VARS_FILE] =
            [
                `-v run_id=${__ENV.RUN_ID}`,
                `-v initial_stock=${STOCK}`,
                `-v dispense_accepted=${counter(data, 'dispense_accepted')}`,
                `-v dispense_rejected=${counter(data, 'dispense_rejected')}`,
                `-v dispense_quantity=${DISPENSE_QUANTITY}`,
                `-v adjust_added=${counter(data, 'adjust_added_units')}`,
                `-v adjust_removed=${counter(data, 'adjust_removed_units')}`,
                `-v adjust_accepted=${counter(data, 'adjust_accepted')}`,
            ].join(' ') + '\n';
    }

    return outputs;
}
