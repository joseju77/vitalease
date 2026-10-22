import { formatDate } from '@/lib/formatDateTime';
import {
    BLOOD_TYPE_LABELS,
    CONTRACEPTIVE_METHOD_LABELS,
    enumLabel,
    MARITAL_STATUS_LABELS,
    SEX_AT_BIRTH_FEMALE,
    SEX_AT_BIRTH_LABELS,
} from '@/lib/patientLabels';
import type { PatientProfile } from '@/types/consultations';

/**
 * A read-only definition-list entry. `sensitive` marks the social security
 * number, emails and phone numbers: the server sends them as `null` to viewers
 * without `patients.view`, so a `null` sensitive entry is hidden instead of
 * rendered as "No registrado".
 */
export interface ProfileField {
    label: string;
    value: string | number | null;
    sensitive?: boolean;
}

export function hasValue(value: string | number | null): boolean {
    return value !== null && value !== '';
}

/** Drops sensitive entries the viewer is not allowed to see (absent from the payload). */
export function visibleFields(fields: ProfileField[]): ProfileField[] {
    return fields.filter((field) => !(field.sensitive && field.value === null));
}

function yesNo(value: boolean): string {
    return value ? 'Sí' : 'No';
}

function withUnit(value: number | null, unit: string): string | null {
    return value === null ? null : `${value} ${unit}`;
}

/** Shown for Female patients, or whenever a history exists regardless of the recorded sex. */
export function showsGynecologicalHistory(profile: PatientProfile): boolean {
    return profile.sex_at_birth === SEX_AT_BIRTH_FEMALE || profile.gynecological_history !== null;
}

export function generalFields(profile: PatientProfile): ProfileField[] {
    const fullName = [profile.first_name, profile.last_name, profile.second_last_name].filter(Boolean).join(' ');
    const enrollmentFields: ProfileField[] =
        profile.enrollment !== null
            ? [
                  { label: 'Inscripción', value: profile.enrollment },
                  { label: 'Número de inscripción', value: profile.enrollment_number },
              ]
            : [{ label: 'Inscripción externa', value: profile.external_enrollment }];
    const familyMedicalUnitFields: ProfileField[] =
        profile.family_medical_unit !== null
            ? [
                  { label: 'Unidad médica familiar', value: profile.family_medical_unit.name },
                  { label: 'Dirección de la unidad', value: profile.family_medical_unit.address },
              ]
            : [{ label: 'Unidad médica familiar (otra)', value: profile.other_family_medical_unit }];

    return visibleFields([
        { label: 'Nombre completo', value: fullName },
        { label: 'Fecha de nacimiento', value: formatDate(profile.birth_date) },
        { label: 'Edad', value: `${profile.age} años` },
        { label: 'Sexo al nacer', value: enumLabel(SEX_AT_BIRTH_LABELS, profile.sex_at_birth) },
        { label: 'Estado civil', value: enumLabel(MARITAL_STATUS_LABELS, profile.marital_status) },
        { label: 'Tipo de sangre', value: enumLabel(BLOOD_TYPE_LABELS, profile.blood_type) },
        { label: 'Número de seguridad social', value: profile.social_security_number, sensitive: true },
        ...enrollmentFields,
        ...familyMedicalUnitFields,
    ]);
}

export function contactFields(profile: PatientProfile): ProfileField[] {
    const contact = profile.contact_information;

    if (contact === null) {
        return [];
    }

    return visibleFields([
        { label: 'Teléfono', value: contact.phone_number, sensitive: true },
        { label: 'Correo personal', value: contact.personal_email, sensitive: true },
        { label: 'Correo institucional', value: contact.institutional_email, sensitive: true },
        { label: 'Dirección', value: contact.address },
        { label: 'Colonia', value: contact.neighborhood },
        { label: 'Código postal', value: contact.zip_code },
        { label: 'Municipio', value: contact.municipality },
    ]);
}

export function otherAilmentFields(profile: PatientProfile): ProfileField[] {
    const otherAilments = profile.other_ailments;

    if (otherAilments === null) {
        return [];
    }

    return [
        { label: 'Cirugías', value: otherAilments.surgeries },
        { label: 'Alergias', value: otherAilments.allergies },
        { label: 'Otros', value: otherAilments.others },
    ];
}

export function gynecologicalHistoryFields(profile: PatientProfile): ProfileField[] {
    const history = profile.gynecological_history;

    if (history === null) {
        return [];
    }

    const papSmearResult =
        history.last_pap_smear_was_positive === null
            ? null
            : history.last_pap_smear_was_positive
              ? 'Positivo'
              : 'Negativo';

    return [
        { label: 'Edad de la menarca', value: withUnit(history.menarche, 'años') },
        { label: 'Duración del ciclo', value: withUnit(history.cycle_duration, 'días') },
        { label: 'Intensidad del ciclo', value: `${history.cycle_intensity} de 10` },
        { label: 'Nivel de flujo', value: history.cycle_flow_level },
        { label: 'Presenta cólicos', value: yesNo(history.has_cramps) },
        { label: 'Ciclo regular', value: yesNo(history.is_cycle_regular) },
        { label: 'Fecha del último ciclo', value: formatDate(history.last_cycle_date) },
        { label: 'Edad de inicio de vida sexual', value: withUnit(history.sexual_activity_start_age, 'años') },
        {
            label: 'Método anticonceptivo',
            value:
                history.contraceptive_method === null
                    ? null
                    : enumLabel(CONTRACEPTIVE_METHOD_LABELS, history.contraceptive_method),
        },
        {
            label: 'Fecha del último papanicolaou',
            value: history.last_pap_smear_date === null ? null : formatDate(history.last_pap_smear_date),
        },
        { label: 'Resultado del último papanicolaou', value: papSmearResult },
        { label: 'Embarazos', value: history.pregnancies },
        { label: 'Partos vaginales', value: history.vaginal_deliveries },
        { label: 'Cesáreas', value: history.cesareans },
        { label: 'Abortos', value: history.abortions },
    ];
}
