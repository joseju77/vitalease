<?php

namespace App\Http\Requests\Concerns;

use App\Enums\Permission;
use Illuminate\Validation\Validator;

trait ValidatesPermissionDependencies
{
    /**
     * Add one error on `permissions` for every dependency the granted
     * permissions leave unsatisfied, instead of silently granting it.
     *
     * Names outside the `Permission` catalog are ignored: the enum rule on
     * `permissions.*` already reports submitted ones.
     *
     * @param  array<int, string>  $grantedPermissions
     */
    protected function addMissingPermissionDependencyErrors(Validator $validator, array $grantedPermissions, string $messageKey): void
    {
        $granted = [];

        foreach ($grantedPermissions as $permissionName) {
            $permission = Permission::tryFrom($permissionName);

            if ($permission !== null) {
                $granted[$permission->value] = $permission;
            }
        }

        $granted = array_values($granted);

        foreach (Permission::missingDependencies($granted) as $permissionValue => $missingRequirements) {
            foreach ($missingRequirements as $requirement) {
                $validator->errors()->add('permissions', __($messageKey, [
                    'permission' => Permission::from($permissionValue)->label(),
                    'requirement' => $requirement->label(),
                ]));
            }
        }
    }
}
