import { ADMIN_EMAIL, DISPENSE_QUANTITY, STOCK } from './config.js';
import { discoverPools, jsonOrFail } from './pools.js';
import { login } from './session.js';

export const RUN_ID = __ENV.RUN_ID || `local-${Date.now()}`;

/**
 * Concurrency experiments setup: discover the pools, choose one medication
 * (MEDICATION_NAME, else the first listed) and bring its stock to exactly STOCK
 * through the regular adjustment endpoint (no direct SQL writes). The
 * adjustment note carries RUN_ID so the SQL check can find the baseline
 * movement and measure only what happened after it.
 */
export function prepareLimitedStock() {
    const pools = discoverPools();
    const admin = login(ADMIN_EMAIL);

    if (admin === null) {
        throw new Error('setup(): super-admin sign-in failed');
    }

    const medication = __ENV.MEDICATION_NAME
        ? pools.medications.find((candidate) => candidate.name === __ENV.MEDICATION_NAME)
        : pools.medications[0];

    if (!medication) {
        throw new Error('setup(): the requested medication was not found among the active medications');
    }

    const current = jsonOrFail(
        admin.page(`/inventory/medications/${medication.uuid}`, 'setup.medication'),
        'GET /inventory/medications/{uuid}',
    ).props.medication.current_stock;
    const delta = STOCK - current;
    const notes = `k6 setup ${RUN_ID}`;

    // The baseline movement must exist even when the stock already matches:
    // add and remove one unit in that case.
    const steps = delta === 0 ? [1, -1] : [delta];

    for (const quantity of steps) {
        const response = admin.postJson(
            `/inventory/medications/${medication.uuid}/adjustments`,
            { quantity, notes },
            'setup.adjust',
            [302],
        );

        if (response.status !== 302) {
            throw new Error(`setup(): adjusting stock failed with HTTP ${response.status}`);
        }
    }

    console.log(
        `K6_SETUP ${JSON.stringify({ run_id: RUN_ID, medication_uuid: medication.uuid, medication: medication.name, initial_stock: STOCK, dispense_quantity: DISPENSE_QUANTITY })}`,
    );

    return { ...pools, limited: { uuid: medication.uuid, name: medication.name } };
}
