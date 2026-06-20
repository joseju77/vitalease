<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

class UserPolicy
{
    /**
     * Determine whether the given user can view any users.
     */
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::UsersManage->value);
    }

    /**
     * Determine whether the given user can create users.
     */
    public function create(User $user): bool
    {
        return $user->can(Permission::UsersManage->value);
    }

    /**
     * Determine whether the given user can update the target user.
     */
    public function update(User $user): bool
    {
        return $user->can(Permission::UsersManage->value);
    }
}
