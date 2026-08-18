<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Medication;
use App\Models\User;

class MedicationPolicy
{
    /**
     * Determine whether the given user can view any medications.
     */
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::InventoryView->value);
    }

    /**
     * Determine whether the given user can view the target medication.
     */
    public function view(User $user, Medication $medication): bool
    {
        return $user->can(Permission::InventoryView->value);
    }

    /**
     * Determine whether the given user can create medications.
     */
    public function create(User $user): bool
    {
        return $user->can(Permission::InventoryCreate->value);
    }

    /**
     * Determine whether the given user can update, activate, or deactivate
     * the target medication.
     */
    public function update(User $user, Medication $medication): bool
    {
        return $user->can(Permission::InventoryUpdate->value);
    }

    /**
     * Determine whether the given user can hard-delete the target
     * medication.
     */
    public function delete(User $user, Medication $medication): bool
    {
        return $user->can(Permission::InventoryDelete->value);
    }

    /**
     * Determine whether the given user can record an Entry movement for the
     * target medication.
     */
    public function recordEntry(User $user, Medication $medication): bool
    {
        return $user->can(Permission::InventoryCreate->value);
    }

    /**
     * Determine whether the given user can record an Adjustment movement for
     * the target medication.
     */
    public function recordAdjustment(User $user, Medication $medication): bool
    {
        return $user->can(Permission::InventoryUpdate->value);
    }
}
