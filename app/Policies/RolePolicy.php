<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use Spatie\Permission\Models\Role;

class RolePolicy
{
    /**
     * Determine whether the given user can view any roles.
     */
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::RolesManage->value);
    }

    /**
     * Determine whether the given user can create roles.
     */
    public function create(User $user): bool
    {
        return $user->can(Permission::RolesManage->value);
    }

    /**
     * Determine whether the given user can update the target role.
     *
     * The `super-admin` role is protected unconditionally, even from another
     * `roles.manage` holder: its real power comes from the `Gate::before()`
     * string match in `AppServiceProvider`, not from any permission grant,
     * so renaming or re-syncing its permissions would silently break that
     * bypass.
     */
    public function update(User $user, Role $role): bool
    {
        if ($role->name === 'super-admin') {
            return false;
        }

        return $user->can(Permission::RolesManage->value);
    }

    /**
     * Determine whether the given user can delete the target role.
     *
     * See {@see self::update()} for why `super-admin` is protected
     * unconditionally.
     */
    public function delete(User $user, Role $role): bool
    {
        if ($role->name === 'super-admin') {
            return false;
        }

        return $user->can(Permission::RolesManage->value);
    }
}
