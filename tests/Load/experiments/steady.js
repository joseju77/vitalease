// Steady background load for the failure-injection experiment (kill an app task,
// meilisearch to 0, stop redis/postgres, restart nginx). Constant VUs on the
// mixed workload; thresholds only report, because errors during an injected
// failure are the data being measured. Correlate with events.csv and health.csv.
//
//   -e STEADY_VUS=20   -e DURATION=10m
import { sleep } from 'k6';
import { buildThresholds, SUMMARY_TREND_STATS, THINK_TIME_SECONDS } from '../lib/config.js';
import { MIX_NAMES, mixedIteration } from '../lib/mix.js';
import { discoverPools } from '../lib/pools.js';
import { buildSummaryOutputs } from '../lib/summary.js';

export const options = {
    vus: parseInt(__ENV.STEADY_VUS || '20', 10),
    duration: __ENV.DURATION || '10m',
    thresholds: buildThresholds([], MIX_NAMES),
    summaryTrendStats: SUMMARY_TREND_STATS,
};

export function setup() {
    return discoverPools();
}

export default function (pools) {
    mixedIteration(pools);
    sleep(THINK_TIME_SECONDS);
}

export function handleSummary(data) {
    return buildSummaryOutputs(data);
}
