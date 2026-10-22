<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Patient;
use App\Models\User;

/**
 * Patient abilities.
 *
 * `super-admin` bypasses this policy through `Gate::before()`, so the state
 * invariants for that role (a disabled patient is not editable, disabling
 * needs an active patient, enabling a disabled one) are enforced again by
 * routing and by the services.
 */
class PatientPolicy
{
    /**
     * Determine whether the given user can view any patients.
     */
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::PatientsView->value);
    }

    /**
     * Determine whether the given user can view the target patient. Disabled
     * patients remain viewable.
     */
    public function view(User $user, Patient $patient): bool
    {
        return $user->can(Permission::PatientsView->value);
    }

    /**
     * Determine whether the given user can edit the target patient: requires
     * the permission and an active patient.
     */
    public function update(User $user, Patient $patient): bool
    {
        return $patient->disabled_at === null && $user->can(Permission::PatientsUpdate->value);
    }

    /**
     * Determine whether the given user can disable the target patient:
     * requires the permission and an active patient.
     */
    public function disable(User $user, Patient $patient): bool
    {
        return $patient->disabled_at === null && $user->can(Permission::PatientsDisable->value);
    }

    /**
     * Determine whether the given user can re-enable the target patient:
     * requires the disable permission and a disabled patient.
     */
    public function enable(User $user, Patient $patient): bool
    {
        return $patient->disabled_at !== null && $user->can(Permission::PatientsDisable->value);
    }

    /**
     * Determine whether the given user can read the target patient's change
     * history.
     */
    public function audit(User $user, Patient $patient): bool
    {
        return $user->can(Permission::PatientsAudit->value) && $user->can(Permission::PatientsView->value);
    }
}
