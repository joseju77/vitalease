import { Counter } from 'k6/metrics';
import { check } from 'k6';

export const createAccepted = new Counter('create_accepted');
export const createRejected = new Counter('create_rejected');
export const dispenseAccepted = new Counter('dispense_accepted');
export const dispenseRejected = new Counter('dispense_rejected');
export const adjustAccepted = new Counter('adjust_accepted');
export const adjustRejected = new Counter('adjust_rejected');
export const adjustAddedUnits = new Counter('adjust_added_units');
export const adjustRemovedUnits = new Counter('adjust_removed_units');

/** A valid consultation payload dispensing `quantity` units of one medication. */
export function consultationPayload(patientUuid, medicationUuid, quantity) {
    return {
        patient_uuid: patientUuid,
        consultation: {
            current_condition: 'Load test: patient reports mild symptoms for two days.',
            diagnosis: 'Load test: upper respiratory infection.',
        },
        condition: 1,
        prognosis: 1,
        medical_classification: 3,
        treatment: [
            {
                medication_uuid: medicationUuid,
                quantity_dispensed: quantity,
                dose: '1 unit',
                frequency: 'Every 8 hours',
                duration: '3 days',
            },
        ],
        vital_signs: {
            weight: 70.5,
            height: 1.7,
            blood_pressure_systolic: 120,
            blood_pressure_diastolic: 80,
            heart_rate: 72,
            respiratory_rate: 16,
            temperature: 36.6,
            oxygen_saturation: 98,
            glasgow: 15,
            glucose: 90,
        },
        physical_examination: {
            neurological: 'Alert and oriented.',
            head_neck: 'No abnormalities.',
            thorax_cardiopulmonary: 'Clear breath sounds.',
            abdomen: 'Soft, non-tender.',
            extremities: 'No edema.',
            cabinet_laboratory: 'Not requested.',
        },
        // The writer reads this key unconditionally, so it is sent explicitly as the form does.
        regulation: null,
    };
}

/**
 * Submit a consultation and classify the outcome: 302 is an accepted creation,
 * 422 a business rejection (insufficient stock), anything else is a failure.
 */
export function submitConsultation(session, payload, name, accepted, rejected) {
    const response = session.postJson('/consultations', payload, name);

    if (response.status === 302) {
        accepted.add(1);
    } else if (response.status === 422) {
        rejected.add(1);
    }

    check(response, {
        'consultation accepted or rejected by validation': (res) => res.status === 302 || res.status === 422,
    });

    return response;
}

/** Record a signed stock adjustment and classify it like a consultation. */
export function submitAdjustment(session, medicationUuid, quantity, notes, name) {
    const response = session.postJson(
        `/inventory/medications/${medicationUuid}/adjustments`,
        { quantity, notes },
        name,
    );

    if (response.status === 302) {
        adjustAccepted.add(1);

        if (quantity > 0) {
            adjustAddedUnits.add(quantity);
        } else {
            adjustRemovedUnits.add(-quantity);
        }
    } else if (response.status === 422) {
        adjustRejected.add(1);
    }

    check(response, {
        'adjustment accepted or rejected by validation': (res) => res.status === 302 || res.status === 422,
    });

    return response;
}
