import { check } from 'k6';
import { ADMIN_EMAIL, CLINICAL_FALLBACK_EMAIL, POOL_CONSULTATION_PATIENTS, POOL_PATIENT_PAGES } from './config.js';
import { login } from './session.js';

function pick(list) {
    return list[Math.floor(Math.random() * list.length)];
}

export { pick };

/** Parse a setup response as JSON, failing with a readable message otherwise. */
export function jsonOrFail(response, what) {
    if (response.status !== 200) {
        throw new Error(
            `setup(): ${what} answered HTTP ${response.status}; check the account's permissions and that the demo data is seeded`,
        );
    }

    return response.json();
}

function requireSession(email) {
    const session = login(email);

    if (session === null) {
        throw new Error(`setup(): cannot sign in as ${email}; is the demo data seeded and PASSWORD correct?`);
    }

    return session;
}

/** Search terms taken from real patient names, so searches match like a user's would. */
function termsFrom(patients) {
    const terms = [];

    for (const patient of patients) {
        const words = String(patient.full_name)
            .split(' ')
            .filter((word) => word.length >= 4);

        if (words.length > 0) {
            terms.push(pick(words).slice(0, 5 + Math.floor(Math.random() * 3)));
        }
    }

    return terms.length > 0 ? terms : ['ana'];
}

/**
 * Discover the data the scenarios read, through the application's own pages
 * (no SQL): a spread of patients (random pages), their consultations and the
 * active medications with stock.
 *
 * @returns {{patients: {uuid: string, full_name: string}[], terms: string[], consultations: string[], medications: {uuid: string, name: string, current_stock: number}[]}}
 */
export function discoverPools() {
    const clinical = requireSession(CLINICAL_FALLBACK_EMAIL);
    const admin = requireSession(ADMIN_EMAIL);

    const first = jsonOrFail(clinical.page('/patients', 'setup.patients'), 'GET /patients');
    const lastPage = Math.max(1, first.props.patients.last_page || 1);
    const patients = [...first.props.patients.data];

    for (let index = 1; index < POOL_PATIENT_PAGES; index++) {
        const page = 1 + Math.floor(Math.random() * lastPage);
        patients.push(
            ...jsonOrFail(clinical.page(`/patients?page=${page}`, 'setup.patients'), 'GET /patients').props.patients
                .data,
        );
    }

    const activePatients = patients.filter((patient) => !patient.is_disabled);

    const consultations = [];

    for (const patient of activePatients.slice(0, POOL_CONSULTATION_PATIENTS)) {
        const summary = jsonOrFail(
            clinical.json(`/dashboard/patients/${patient.uuid}`, 'setup.patient-summary'),
            'GET /dashboard/patients/{uuid}',
        );

        for (const item of summary.latest_consultations) {
            consultations.push(item.uuid);
        }
    }

    const medications = [];
    let lastMedicationPage = 1;

    for (let page = 1; page <= lastMedicationPage; page++) {
        const props = jsonOrFail(admin.page(`/inventory?page=${page}`, 'setup.inventory'), 'GET /inventory').props
            .medications;
        lastMedicationPage = props.last_page || 1;
        medications.push(...props.data.filter((medication) => medication.is_active));
    }

    check(null, {
        'setup found patients': () => activePatients.length > 0,
        'setup found consultations': () => consultations.length > 0,
        'setup found medications': () => medications.length > 0,
    });

    if (activePatients.length === 0 || medications.length === 0) {
        throw new Error('setup(): the dataset is empty; run `make swarm-seed-demo` first');
    }

    return {
        patients: activePatients.map((patient) => ({ uuid: patient.uuid, full_name: patient.full_name })),
        terms: termsFrom(activePatients),
        consultations,
        medications: medications.map((medication) => ({
            uuid: medication.uuid,
            name: medication.name,
            current_stock: medication.current_stock,
        })),
    };
}
