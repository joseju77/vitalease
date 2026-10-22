<?php

namespace App\Support\Patients;

use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Maps a database unique-constraint violation on the patient aggregate to the
 * request field it belongs to, by constraint name. Used when two writers race
 * past the validation-time uniqueness rules.
 */
final class PatientUniqueViolation
{
    /**
     * Constraint name => [field key, message key under `modules/patients/management.duplicates`].
     *
     * @var array<string, array{string, string}>
     */
    private const array CONSTRAINTS = [
        'patients_social_security_number_unique' => ['patient.social_security_number', 'social_security_number'],
        'patients_enrollment_id_enrollment_number_unique' => ['patient.enrollment_number', 'enrollment_number'],
        'patient_contact_information_institutional_email_unique' => ['contact_information.institutional_email', 'institutional_email'],
    ];

    /**
     * The detailed validation message keyed by request field, or null when
     * the violated constraint is not one of the patient uniqueness rules.
     *
     * @return array<string, string>|null
     */
    public static function fieldErrors(UniqueConstraintViolationException $exception): ?array
    {
        foreach (self::CONSTRAINTS as $constraint => [$field, $messageKey]) {
            if (str_contains($exception->getMessage(), '"'.$constraint.'"')) {
                return [$field => __('modules/patients/management.duplicates.'.$messageKey)];
            }
        }

        return null;
    }
}
