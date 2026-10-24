import { pick } from './pools.js';
import { iteration as consultationCreate } from '../scenarios/consultation-create.js';
import { iteration as consultationView } from '../scenarios/consultation-view.js';
import { iteration as demandProjection } from '../scenarios/demand-projection.js';
import { iteration as inventoryList } from '../scenarios/inventory-list.js';
import { iteration as patientDetail } from '../scenarios/patient-detail.js';
import { iteration as patientSearch } from '../scenarios/patient-search.js';
import { NAMES as consultationCreateNames } from '../scenarios/consultation-create.js';
import { NAMES as consultationViewNames } from '../scenarios/consultation-view.js';
import { NAMES as demandProjectionNames } from '../scenarios/demand-projection.js';
import { NAMES as inventoryListNames } from '../scenarios/inventory-list.js';
import { NAMES as patientDetailNames } from '../scenarios/patient-detail.js';
import { NAMES as patientSearchNames } from '../scenarios/patient-search.js';

/**
 * Realistic mixed workload: most traffic is reading patients and consultations,
 * a minority writes consultations, a few hit the heavy inventory/report pages.
 * Weights are relative; edit here to change every experiment at once.
 */
const WORKLOAD = [
    { weight: 30, run: patientSearch },
    { weight: 20, run: patientDetail },
    { weight: 20, run: consultationView },
    { weight: 15, run: consultationCreate },
    { weight: 10, run: inventoryList },
    { weight: 5, run: demandProjection },
];

export const MIX_NAMES = [
    ...patientSearchNames,
    ...patientDetailNames,
    ...consultationViewNames,
    ...consultationCreateNames,
    ...inventoryListNames,
    ...demandProjectionNames,
];

const TOTAL_WEIGHT = WORKLOAD.reduce((sum, entry) => sum + entry.weight, 0);

/** Run one weighted-random operation of the mixed workload. */
export function mixedIteration(pools) {
    let target = Math.random() * TOTAL_WEIGHT;

    for (const entry of WORKLOAD) {
        target -= entry.weight;

        if (target <= 0) {
            entry.run(pools);

            return;
        }
    }

    pick(WORKLOAD).run(pools);
}
