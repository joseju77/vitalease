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
     */
    public function view(User $user): bool
    {
        return $user->can(Permission::ConsultationsView->value);
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
     * Requires the granular permission AND ownership: the user must be the
     * consultation's physician. `super-admin` bypasses this only through
     * `Gate::before()` in `AppServiceProvider` — this policy never
     * special-cases any role.
     */
    public function update(User $user, MedicalConsultation $consultation): bool
    {
        return $user->can(Permission::ConsultationsUpdate->value)
            && $consultation->physician_id === $user->id;
    }

    /**
     * Determine whether the given user can delete the target consultation.
     *
     * See {@see self::update()} for the same permission-AND-ownership rule.
     */
    public function delete(User $user, MedicalConsultation $consultation): bool
    {
        return $user->can(Permission::ConsultationsDelete->value)
            && $consultation->physician_id === $user->id;
    }
}
