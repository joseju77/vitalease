/**
 * Human-readable Spanish labels for the patient enums defined in
 * `app/Enums/{SexAtBirth,MaritalStatus,BloodType,KinshipType,AilmentType,ContraceptiveMethod}.php`.
 * The backend only ever sends the raw integer enum value. Shared by the
 * registration steps, the dashboard patient summary dialog, and the
 * consultation patient profile card, following the `lib/consultationLabels.ts`
 * precedent.
 */

/** Labels for `App\Enums\SexAtBirth`. */
export const SEX_AT_BIRTH_LABELS: Record<number, string> = {
    1: 'Masculino',
    2: 'Femenino',
};

/** Labels for `App\Enums\MaritalStatus`. */
export const MARITAL_STATUS_LABELS: Record<number, string> = {
    1: 'Soltero(a)',
    2: 'Casado(a)',
    3: 'Divorciado(a)',
    4: 'Viudo(a)',
};

/** Labels for `App\Enums\BloodType`. */
export const BLOOD_TYPE_LABELS: Record<number, string> = {
    1: 'A+',
    2: 'A-',
    3: 'B+',
    4: 'B-',
    5: 'AB+',
    6: 'AB-',
    7: 'O+',
    8: 'O-',
};

/** Labels for `App\Enums\KinshipType`. */
export const KINSHIP_TYPE_LABELS: Record<number, string> = {
    1: 'Padre/Madre',
    2: 'Hermano(a)',
    3: 'Cónyuge',
    4: 'Hijo(a)',
    5: 'Amigo(a)',
    6: 'Otro',
};

/** Labels for `App\Enums\AilmentType`. */
export const AILMENT_TYPE_LABELS: Record<number, string> = {
    1: 'Diabetes',
    2: 'Hipertensión',
    3: 'Epilepsia',
    4: 'Neumopatías',
    5: 'Cardiopatías',
    6: 'Cáncer',
};

/** Labels for `App\Enums\ContraceptiveMethod`. */
export const CONTRACEPTIVE_METHOD_LABELS: Record<number, string> = {
    1: 'Condón',
    2: 'Píldoras orales',
    3: 'DIU',
    4: 'Implante',
    5: 'Inyección',
    6: 'Parche',
    7: 'Anillo',
    8: 'Métodos de barrera',
    9: 'Esterilización',
    10: 'Otro',
    11: 'Ninguno',
};

/** `App\Enums\SexAtBirth::Female`. */
export const SEX_AT_BIRTH_FEMALE = 2;

/** Returns the label for `value` in `labels`, falling back to the raw value. */
export function enumLabel(labels: Record<number, string>, value: number): string {
    return labels[value] ?? String(value);
}
