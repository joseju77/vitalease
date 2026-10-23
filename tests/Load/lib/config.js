// Shared configuration for every k6 script. All values come from environment
// variables (`k6 run -e NAME=value`), so the same scripts run from any machine.

export const BASE_URL = (__ENV.BASE_URL || '').replace(/\/+$/, '');

if (BASE_URL === '') {
    throw new Error(
        'BASE_URL is required, e.g. -e BASE_URL=http://192.168.1.10 (the published nginx port of the stack)',
    );
}

// Demo accounts created by `demo:seed` share the factory password.
export const PASSWORD = __ENV.PASSWORD || 'password';

// Physician accounts are medico1@vitalease.test .. medico{USER_COUNT}@vitalease.test
// (100 seeded users leave 96 physicians besides the fixed accounts).
export const USER_COUNT = parseInt(__ENV.USER_COUNT || '96', 10);

// The super-admin account holds the inventory and report permissions that the
// physician role lacks.
export const ADMIN_EMAIL = __ENV.ADMIN_EMAIL || 'superadmin@vitalease.test';
export const CLINICAL_FALLBACK_EMAIL = 'medico.demo@vitalease.test';

export const THINK_TIME_SECONDS = parseFloat(__ENV.THINK_TIME || '1');
export const P95_MS = parseInt(__ENV.P95_MS || '1000', 10);
export const ERROR_RATE = parseFloat(__ENV.ERROR_RATE || '0.01');

// Pool sizes discovered in setup() through the application itself.
export const POOL_PATIENT_PAGES = parseInt(__ENV.POOL_PATIENT_PAGES || '8', 10);
export const POOL_CONSULTATION_PATIENTS = parseInt(__ENV.POOL_CONSULTATION_PATIENTS || '40', 10);

/**
 * Account for the current VU: a physician spread over the seeded accounts, or
 * the super-admin for operations the physician role may not perform.
 *
 * @param {'clinical' | 'admin'} role
 */
export function emailForVu(role) {
    if (role === 'admin') {
        return ADMIN_EMAIL;
    }

    return `medico${(__VU % USER_COUNT) + 1}@vitalease.test`;
}

/**
 * Always-true thresholds that make k6 export the measured-operation stats
 * (requests tagged kind:op, see lib/session.js) separately from login and setup.
 */
export function reportingThresholds() {
    return {
        'http_req_duration{kind:op}': ['max>=0'],
        'http_reqs{kind:op}': ['count>=0'],
        'http_req_failed{kind:op}': ['rate>=0'],
        'http_req_duration{kind:auth}': ['max>=0'],
        'http_reqs{kind:auth}': ['count>=0'],
        'http_req_failed{kind:auth}': ['rate>=0'],
    };
}

/**
 * Thresholds for a set of named requests: p95 latency per request name and the
 * overall failure rate. Names that only need reporting (no pass/fail) are
 * declared with an always-true threshold so k6 still exports their stats.
 *
 * @param {string[]} gatedNames
 * @param {string[]} [reportedNames]
 */
export function buildThresholds(gatedNames, reportedNames = []) {
    const thresholds = {
        ...reportingThresholds(),
        http_req_failed: [`rate<${ERROR_RATE}`],
    };

    for (const name of gatedNames) {
        thresholds[`http_req_duration{name:${name}}`] = [`p(95)<${P95_MS}`];
    }

    for (const name of reportedNames) {
        thresholds[`http_req_duration{name:${name}}`] = ['max>=0'];
    }

    return thresholds;
}

// Concurrency experiments (see experiments/concurrency-*.js).
export const STOCK = parseInt(__ENV.STOCK || '200', 10);
export const DISPENSE_QUANTITY = parseInt(__ENV.DISPENSE_QUANTITY || '1', 10);

export const SUMMARY_TREND_STATS = ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'];

/**
 * Common options of a single-scenario script: constant VUs for a duration
 * (-e VUS=10 -e DURATION=60s) with the scenario's thresholds.
 */
export function scenarioOptions(gatedNames, reportedNames = []) {
    return {
        vus: parseInt(__ENV.VUS || '10', 10),
        duration: __ENV.DURATION || '60s',
        thresholds: buildThresholds(gatedNames, reportedNames),
        summaryTrendStats: SUMMARY_TREND_STATS,
    };
}
