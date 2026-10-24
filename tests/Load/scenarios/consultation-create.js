// Consultation creation with dispensation: opens the form, then posts a valid
// consultation dispensing one unit. 422 (insufficient stock) is a counted
// business rejection, not an error; the stock is large enough for it to be rare.
import { sleep } from 'k6';
import { emailForVu, scenarioOptions, THINK_TIME_SECONDS } from '../lib/config.js';
import { expectOk } from '../lib/checks.js';
import { consultationPayload, createAccepted, createRejected, submitConsultation } from '../lib/consultation.js';
import { discoverPools, pick } from '../lib/pools.js';
import { vuSessionFor } from '../lib/session.js';
import { buildSummaryOutputs } from '../lib/summary.js';

export const NAMES = ['consultations.create-form', 'consultations.store'];
export const options = scenarioOptions(NAMES);

export function setup() {
    return discoverPools();
}

export function iteration(pools) {
    const session = vuSessionFor(emailForVu('clinical'));

    if (session === null) {
        return;
    }

    const patient = pick(pools.patients);
    const medication = pick(pools.medications);

    expectOk(
        session.page(`/consultations/create?patient=${patient.uuid}`, 'consultations.create-form'),
        'consultations.create-form',
    );
    submitConsultation(
        session,
        consultationPayload(patient.uuid, medication.uuid, 1),
        'consultations.store',
        createAccepted,
        createRejected,
    );
}

export default function (pools) {
    iteration(pools);
    sleep(THINK_TIME_SECONDS);
}

export function handleSummary(data) {
    return buildSummaryOutputs(data);
}
