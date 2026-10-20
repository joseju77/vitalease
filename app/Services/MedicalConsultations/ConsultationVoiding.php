<?php

namespace App\Services\MedicalConsultations;

use App\Models\MedicalConsultation;
use App\Models\MedicalConsultationTreatment;
use App\Models\User;
use App\Services\Inventory\InsufficientStockException;
use App\Services\Inventory\TreatmentDispensation;
use App\Support\Auditing\AuditBatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use OwenIt\Auditing\Events\AuditCustom;

/**
 * Retires a consultation (void) or brings it back (restore) atomically.
 *
 * Both operations lock the consultation row and re-check its state inside
 * the transaction, so of two concurrent requests exactly one takes effect
 * and stock moves once. They also reject an invalid state for every actor,
 * including `super-admin`, who bypasses the policy. The three void columns
 * are always written together in one save, as the database requires, and
 * each operation records exactly one custom audit event carrying the reason.
 */
final readonly class ConsultationVoiding
{
    public function __construct(
        private TreatmentDispensation $treatmentDispensation,
        private AuditBatch $auditBatch,
    ) {}

    /**
     * Void the consultation: return its dispensed stock to inventory, keep
     * its treatment lines and record who voided it and why.
     *
     * @throws ValidationException When the consultation is already voided.
     */
    public function void(MedicalConsultation $consultation, User $actor, string $reason): MedicalConsultation
    {
        return $this->auditBatch->within(fn (): MedicalConsultation => DB::transaction(function () use ($consultation, $actor, $reason): MedicalConsultation {
            $locked = $this->lock($consultation);

            if ($locked->trashed()) {
                throw ValidationException::withMessages([
                    'consultation' => [__('modules/consultations/management.custom.already_voided')],
                ]);
            }

            $this->treatmentDispensation->reverseAll($locked, $actor);

            $locked->forceFill([
                'voided_at' => now(),
                'voided_by_id' => $actor->id,
                'void_reason' => $reason,
            ])->saveQuietly();

            $this->recordAudit($locked, 'voided', $reason);

            return $locked;
        }));
    }

    /**
     * Restore a voided consultation: dispense its preserved treatment lines
     * again and clear the current void. Inactive medications are allowed;
     * insufficient stock rejects the whole restore.
     *
     * @throws ValidationException When the consultation is not voided, or when any line lacks stock (one error per line, keyed `treatment.{position}`).
     */
    public function restore(MedicalConsultation $consultation, User $actor, string $reason): MedicalConsultation
    {
        try {
            return $this->auditBatch->within(fn (): MedicalConsultation => DB::transaction(function () use ($consultation, $actor, $reason): MedicalConsultation {
                $locked = $this->lock($consultation);

                if (! $locked->trashed()) {
                    throw ValidationException::withMessages([
                        'consultation' => [__('modules/consultations/management.custom.not_voided')],
                    ]);
                }

                $this->treatmentDispensation->redispenseAll($locked, $actor);

                $locked->forceFill([
                    'voided_at' => null,
                    'voided_by_id' => null,
                    'void_reason' => null,
                ])->saveQuietly();

                $this->recordAudit($locked, 'restored', $reason);

                return $locked;
            }));
        } catch (InsufficientStockException $e) {
            throw $this->restoreStockException($consultation, $e);
        }
    }

    /**
     * Re-fetch the consultation (voided or not) under a row lock, so the
     * state check below reads the committed truth rather than a stale
     * route-bound instance.
     */
    private function lock(MedicalConsultation $consultation): MedicalConsultation
    {
        return MedicalConsultation::withTrashed()
            ->whereKey($consultation->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Record a custom audit event (not a full attribute snapshot) carrying
     * the reason. The actor and the batch are resolved by the audit
     * resolvers like any other audit.
     */
    private function recordAudit(MedicalConsultation $consultation, string $event, string $reason): void
    {
        if (! MedicalConsultation::isAuditingEnabled()) {
            return;
        }

        $consultation->auditEvent = $event;
        $consultation->isCustomEvent = true;
        $consultation->auditCustomOld = [];
        $consultation->auditCustomNew = ['reason' => $reason];

        try {
            Event::dispatch(new AuditCustom($consultation));
        } finally {
            $consultation->isCustomEvent = false;
            $consultation->auditCustomOld = null;
            $consultation->auditCustomNew = null;
        }
    }

    /**
     * Turn the per-position availability map into one validation error per
     * offending line, naming its medication.
     */
    private function restoreStockException(MedicalConsultation $consultation, InsufficientStockException $exception): ValidationException
    {
        $lines = $consultation->treatments()->with('medication')->orderBy('id')->get()->values();
        $messages = [];

        foreach ($exception->available() as $position => $available) {
            /** @var MedicalConsultationTreatment $line */
            $line = $lines->get($position);

            $messages["treatment.{$position}"] = [__('modules/consultations/management.custom.restore_insufficient_stock', [
                'medication' => $line->medication->auditLabel(),
                'available' => $available,
            ])];
        }

        return ValidationException::withMessages($messages);
    }
}
