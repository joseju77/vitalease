// Experiment 1: scaling. Ramps through stepped VU levels (VU_STEPS) on the mixed
// workload; run once with app x1 and once with app x3, same script and dataset.
//
//   -e VU_STEPS=5,25,50,100   -e STEP_DURATION=2m   -e RAMP=15s
import { sleep } from 'k6';
import { buildThresholds, SUMMARY_TREND_STATS, THINK_TIME_SECONDS } from '../lib/config.js';
import { MIX_NAMES, mixedIteration } from '../lib/mix.js';
import { discoverPools } from '../lib/pools.js';
import { buildSummaryOutputs } from '../lib/summary.js';

const steps = (__ENV.VU_STEPS || '5,25,50,100').split(',').map((value) => parseInt(value, 10));
const stepDuration = __ENV.STEP_DURATION || '2m';
const ramp = __ENV.RAMP || '15s';

// Hold each level for STEP_DURATION after a short ramp, so every step has a
// steady plateau that can be compared between the x1 and x3 runs.
const stages = steps.flatMap((target) => [
    { duration: ramp, target },
    { duration: stepDuration, target },
]);

export const options = {
    scenarios: {
        scaling: { executor: 'ramping-vus', startVUs: 0, stages, gracefulRampDown: '10s' },
    },
    // Pass/fail is informative here: the point is the curve, so every request is only reported.
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
