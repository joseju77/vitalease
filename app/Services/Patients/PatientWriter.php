<?php

namespace App\Services\Patients;

use App\Models\Patient;
use App\Support\Auditing\AuditBatch;
use App\Support\Patients\PatientUniqueViolation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Writes a patient aggregate (patient, contact information, emergency
 * contacts, ailments, other ailments and gynecological history) atomically.
 * Every audit row produced by one call shares a single audit batch, so one
 * registration reads as one change in the history.
 *
 * `$data` is the validated request payload. Every row is written through
 * its model, never the query builder, so each one is audited.
 *
 * @phpstan-type PatientData array{
 *     patient: array<string, mixed>,
 *     contact_information: array<string, mixed>,
 *     emergency_contacts?: list<array<string, mixed>>,
 *     ailments?: list<array<string, mixed>>,
 *     other_ailments?: array<string, mixed>|null,
 *     gynecological_history?: array<string, mixed>|null
 * }
 */
final readonly class PatientWriter
{
    private const array NULLABLE_PATIENT_FIELDS = [
        'second_last_name',
        'enrollment_id',
        'enrollment_number',
        'external_enrollment',
        'family_medical_unit_id',
        'other_family_medical_unit',
    ];

    private const array NULLABLE_CONTACT_FIELDS = ['institutional_email', 'neighborhood_id'];

    private const array NULLABLE_GYNECOLOGICAL_FIELDS = [
        'sexual_activity_start_age',
        'contraceptive_method',
        'last_pap_smear_date',
        'last_pap_smear_was_positive',
    ];

    public function __construct(private AuditBatch $auditBatch) {}

    /**
     * Create the patient aggregate. Public self-registration has no
     * authenticated user, so its audits carry a null user (the history
     * reads it as "Autorregistro") and keep the request IP and user agent.
     *
     * @param  PatientData  $data
     */
    public function register(array $data): Patient
    {
        return $this->auditBatch->within(fn (): Patient => DB::transaction(function () use ($data): Patient {
            $patient = Patient::query()->create($data['patient']);

            $contactInformation = $data['contact_information'];
            unset($contactInformation['zip_code']);
            $patient->contactInformation()->create($contactInformation);

            foreach ($data['emergency_contacts'] ?? [] as $emergencyContact) {
                $patient->emergencyContacts()->create($emergencyContact);
            }

            foreach ($data['ailments'] ?? [] as $ailment) {
                $patient->ailments()->create($ailment);
            }

            if (($data['other_ailments'] ?? null) !== null) {
                $patient->otherAilments()->create($data['other_ailments']);
            }

            if (($data['gynecological_history'] ?? null) !== null) {
                $patient->gynecologicalHistory()->create($data['gynecological_history']);
            }

            return $patient;
        }));
    }

    /**
     * Update the patient aggregate. Runs in one transaction and one audit
     * batch, locking the patient row first: a patient disabled meanwhile (the
     * policy cannot see that for `super-admin`, nor a concurrent disable) is
     * rejected with a 422 on `patient`.
     *
     * Nothing is deleted and recreated blindly: every row is `fill()` +
     * `save()`, so an untouched row is not dirty and produces no audit.
     * One-to-one sections are updated in place, created, or removed with the
     * model's `delete()` when the payload omits them. Emergency contacts are
     * matched by phone number and ailments by type; removed rows go first so
     * a re-keyed row never collides with one about to disappear.
     *
     * A race on a unique constraint is reported as the same detailed field
     * error the request's uniqueness rules give.
     *
     * @param  PatientData  $data
     *
     * @throws ValidationException
     */
    public function update(Patient $patient, array $data): Patient
    {
        try {
            return $this->auditBatch->within(fn (): Patient => DB::transaction(function () use ($patient, $data): Patient {
                $locked = Patient::query()->whereKey($patient->getKey())->lockForUpdate()->firstOrFail();

                if ($locked->disabled_at !== null) {
                    throw ValidationException::withMessages([
                        'patient' => __('modules/patients/management.errors.patient_disabled'),
                    ]);
                }

                $locked->fill($this->withNulls($data['patient'], self::NULLABLE_PATIENT_FIELDS))->save();

                $this->syncContactInformation($locked, $data['contact_information']);
                $this->syncEmergencyContacts($locked, $data['emergency_contacts'] ?? []);
                $this->syncAilments($locked, $data['ailments'] ?? []);
                $this->syncOptionalSection($locked->otherAilments()->first(), $data['other_ailments'] ?? null, ['surgeries', 'allergies', 'others'], fn (array $attributes) => $locked->otherAilments()->create($attributes));
                $this->syncOptionalSection($locked->gynecologicalHistory()->first(), $data['gynecological_history'] ?? null, self::NULLABLE_GYNECOLOGICAL_FIELDS, fn (array $attributes) => $locked->gynecologicalHistory()->create($attributes));

                return $locked;
            }));
        } catch (UniqueConstraintViolationException $exception) {
            $errors = PatientUniqueViolation::fieldErrors($exception);

            if ($errors === null) {
                throw $exception;
            }

            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<string, mixed>  $contactInformation
     */
    private function syncContactInformation(Patient $patient, array $contactInformation): void
    {
        unset($contactInformation['zip_code']);
        $contactInformation = $this->withNulls($contactInformation, self::NULLABLE_CONTACT_FIELDS);

        $existing = $patient->contactInformation()->first();

        if ($existing === null) {
            $patient->contactInformation()->create($contactInformation);

            return;
        }

        $existing->fill($contactInformation)->save();
    }

    /**
     * @param  list<array<string, mixed>>  $emergencyContacts
     */
    private function syncEmergencyContacts(Patient $patient, array $emergencyContacts): void
    {
        $existing = $patient->emergencyContacts()->get()->keyBy('phone_number');
        $incoming = collect($emergencyContacts)->keyBy('phone_number');

        foreach ($existing->diffKeys($incoming) as $removed) {
            $removed->delete();
        }

        foreach ($incoming as $phoneNumber => $attributes) {
            $match = $existing->get($phoneNumber);

            if ($match === null) {
                $patient->emergencyContacts()->create($attributes);

                continue;
            }

            $match->fill($attributes)->save();
        }
    }

    /**
     * @param  list<array<string, mixed>>  $ailments
     */
    private function syncAilments(Patient $patient, array $ailments): void
    {
        $existing = $patient->ailments()->get()->keyBy(fn ($ailment): int => $ailment->ailment_type->value);
        $incoming = collect($ailments)->keyBy(fn (array $ailment): int => (int) $ailment['ailment_type']);

        foreach ($existing->diffKeys($incoming) as $removed) {
            $removed->delete();
        }

        foreach ($incoming as $type => $attributes) {
            $attributes = $this->withNulls($attributes, ['treatment_notes']);
            $match = $existing->get($type);

            if ($match === null) {
                $patient->ailments()->create($attributes);

                continue;
            }

            $match->fill($attributes)->save();
        }
    }

    /**
     * Update in place, create, or delete a one-to-one section depending on
     * whether the payload carries it. An empty payload means "not applicable".
     *
     * @param  array<string, mixed>|null  $attributes
     * @param  list<string>  $nullableFields
     * @param  callable(array<string, mixed>): mixed  $create
     */
    private function syncOptionalSection(?Model $existing, ?array $attributes, array $nullableFields, callable $create): void
    {
        if ($attributes === null || $attributes === []) {
            $existing?->delete();

            return;
        }

        $attributes = $this->withNulls($attributes, $nullableFields);

        if ($existing === null) {
            $create($attributes);

            return;
        }

        $existing->fill($attributes)->save();
    }

    /**
     * An edit replaces the whole aggregate: a nullable field the payload does
     * not mention is cleared, not kept.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $nullableFields
     * @return array<string, mixed>
     */
    private function withNulls(array $attributes, array $nullableFields): array
    {
        return $attributes + array_fill_keys($nullableFields, null);
    }
}
