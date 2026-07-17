<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Patient;
use App\Models\User;

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
     * Determine whether the given user can view the target patient.
     */
    public function view(User $user, Patient $patient): bool
    {
        return $user->can(Permission::PatientsView->value);
    }
}
