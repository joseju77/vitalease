<?php

namespace App\Services\Patients;

use App\Models\Patient;
use App\Support\Auditing\AuditBatch;
use Illuminate\Support\Facades\DB;

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
}
