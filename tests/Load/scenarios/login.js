// Login throughput: every iteration is a fresh session (GET /login + POST /login).
// Password hashing makes this the most CPU-bound operation, so it is measured on its own.
import { check, sleep } from 'k6';
import { emailForVu, scenarioOptions, THINK_TIME_SECONDS } from '../lib/config.js';
import { login } from '../lib/session.js';
import { buildSummaryOutputs } from '../lib/summary.js';

export const NAMES = ['auth.login'];
export const options = scenarioOptions(NAMES, ['auth.login-page']);

export function iteration() {
    const session = login(emailForVu('clinical'));

    check(session, { 'signed in': (value) => value !== null });
}

export default function () {
    iteration();
    sleep(THINK_TIME_SECONDS);
}

export function handleSummary(data) {
    return buildSummaryOutputs(data);
}
