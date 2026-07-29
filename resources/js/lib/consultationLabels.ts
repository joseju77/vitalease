/**
 * Human-readable Spanish labels for the medical consultation enums defined in
 * `app/Enums/{MedicalState,MedicalClassification,TransferType}.php`. The
 * backend only ever sends the raw integer enum value. Shared across the
 * dashboard, form, and detail views (4+ consumers), following the
 * `lib/permissionLabels.ts` precedent.
 */

/** Labels for `App\Enums\MedicalState`, used by both `condition` and `prognosis`. */
export const MEDICAL_STATE_LABELS: Record<number, string> = {
    1: 'Bueno',
    2: 'Regular',
    3: 'Grave',
    4: 'Crítico',
    5: 'Reservado',
};

/** Labels for `App\Enums\MedicalClassification`. */
export const MEDICAL_CLASSIFICATION_LABELS: Record<number, string> = {
    1: 'Traumatología',
    2: 'Otorrinolaringología',
    3: 'Respiratorio',
    4: 'Gastroenterología',
    5: 'Oftalmología',
    6: 'Cardiología',
    7: 'Dermatología',
    8: 'Neurología',
    9: 'Endocrinología',
    10: 'Psiquiatría',
};

/** Labels for `App\Enums\TransferType`. */
export const TRANSFER_TYPE_LABELS: Record<number, string> = {
    1: 'Instituto',
    2: 'Servicios de Salud Municipales',
    3: 'Medios propios',
};

export function medicalStateLabel(value: number): string {
    return MEDICAL_STATE_LABELS[value] ?? String(value);
}

export function medicalClassificationLabel(value: number): string {
    return MEDICAL_CLASSIFICATION_LABELS[value] ?? String(value);
}

export function transferTypeLabel(value: number): string {
    return TRANSFER_TYPE_LABELS[value] ?? String(value);
}
