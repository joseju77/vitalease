<?php

namespace App\Services\Patients;

use App\Models\Patient;
use App\Models\User;
use App\Support\Auditing\AuditBatch;
use App\Support\Auditing\CustomAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Disables a patient or brings them back, atomically.
 *
 * Both operations lock the patient row and re-check its state inside the
 * transaction, so of two concurrent requests exactly one takes effect, and
 * they reject a wrong state for every actor, including `super-admin`, who
 * bypasses the policy. The three disable columns are always written together
 * in one save, as the database requires, and each operation records exactly
 * one custom audit event carrying the reason. The patient's children
 * (consultations, contacts, ailments) are never touched, and nothing is
 * deleted.
 *
 * The save runs inside `Patient::withoutAuditing()` (not `saveQuietly()`) so
 * no empty `updated` audit is written while the Scout observer still
 * reindexes the patient after commit.
 */
final readonly class PatientDisabling
{
    public function __construct(private AuditBatch $auditBatch) {}

    /**
     * Disable the patient, recording who did it and why.
     *
     * @throws ValidationException When the patient is already disabled.
     */
    public function disable(Patient $patient, User $actor, string $reason): Patient
    {
        return $this->auditBatch->within(fn (): Patient => DB::transaction(function () use ($patient, $actor, $reason): Patient {
            $locked = $this->lock($patient);

            if ($locked->disabled_at !== null) {
                throw ValidationException::withMessages([
                    'patient' => [__('modules/patients/management.errors.already_disabled')],
                ]);
            }

            $locked->forceFill([
                'disabled_at' => now(),
                'disabled_by_id' => $actor->id,
                'disable_reason' => $reason,
            ]);

            Patient::withoutAuditing(fn (): bool => $locked->save());

            CustomAudit::record($locked, 'disabled', ['reason' => $reason]);

            return $locked;
        }));
    }

    /**
     * Enable a disabled patient, clearing the current disable state.
     *
     * @throws ValidationException When the patient is not disabled.
     */
    public function enable(Patient $patient, User $actor, string $reason): Patient
    {
        return $this->auditBatch->within(fn (): Patient => DB::transaction(function () use ($patient, $reason): Patient {
            $locked = $this->lock($patient);

            if ($locked->disabled_at === null) {
                throw ValidationException::withMessages([
                    'patient' => [__('modules/patients/management.errors.not_disabled')],
                ]);
            }

            $locked->forceFill([
                'disabled_at' => null,
                'disabled_by_id' => null,
                'disable_reason' => null,
            ]);

            Patient::withoutAuditing(fn (): bool => $locked->save());

            CustomAudit::record($locked, 'enabled', ['reason' => $reason]);

            return $locked;
        }));
    }

    /**
     * Re-fetch the patient under a row lock, so the state check reads the
     * committed truth rather than a stale route-bound instance.
     */
    private function lock(Patient $patient): Patient
    {
        return Patient::query()
            ->whereKey($patient->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }
}
