<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\MedicalConsultation;
use App\Models\User;

class MedicalConsultationPolicy
{
    /**
     * Determine whether the given user can view any consultations.
     */
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ConsultationsView->value);
    }

    /**
     * Determine whether the given user can view the target consultation.
     *
     * A voided consultation is only visible to its owner and to users with
     * `view-voided` or `void-any`.
     */
    public function view(User $user, MedicalConsultation $consultation): bool
    {
        if (! $user->can(Permission::ConsultationsView->value)) {
            return false;
        }

        return ! $consultation->trashed()
            || $consultation->physician_id === $user->id
            || $user->can(Permission::ConsultationsViewVoided->value)
            || $user->can(Permission::ConsultationsVoidAny->value);
    }

    /**
     * Determine whether the given user can create consultations.
     */
    public function create(User $user): bool
    {
        return $user->can(Permission::ConsultationsCreate->value);
    }

    /**
     * Determine whether the given user can update the target consultation.
     *
     * Requires the granular permission AND ownership, and the consultation
     * must not be voided. `super-admin` bypasses this only through
     * `Gate::before()` in `AppServiceProvider` — this policy never
     * special-cases any role, so state invariants for that role are enforced
     * by routing and `ConsultationVoiding`.
     */
    public function update(User $user, MedicalConsultation $consultation): bool
    {
        return ! $consultation->trashed()
            && $user->can(Permission::ConsultationsUpdate->value)
            && $consultation->physician_id === $user->id;
    }

    /**
     * Determine whether the given user can void the target consultation.
     */
    public function void(User $user, MedicalConsultation $consultation): bool
    {
        return ! $consultation->trashed() && $this->canToggleVoid($user, $consultation);
    }

    /**
     * Determine whether the given user can restore the target consultation.
     */
    public function restore(User $user, MedicalConsultation $consultation): bool
    {
        return $consultation->trashed() && $this->canToggleVoid($user, $consultation);
    }

    /**
     * Determine whether the given user can read the change history of the
     * target consultation.
     */
    public function audit(User $user): bool
    {
        return $user->can(Permission::ConsultationsAudit->value);
    }

    /**
     * Voiding and restoring share one rule: `void-any`, or `void` on the
     * user's own consultation.
     */
    private function canToggleVoid(User $user, MedicalConsultation $consultation): bool
    {
        return $user->can(Permission::ConsultationsVoidAny->value)
            || ($user->can(Permission::ConsultationsVoid->value) && $consultation->physician_id === $user->id);
    }
}
