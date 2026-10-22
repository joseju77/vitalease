<?php

namespace App\Http\Requests\Users;

use App\Enums\Permission;
use App\Http\Requests\Concerns\ValidatesPermissionDependencies;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Spatie\Permission\Models\Permission as PermissionModel;

class UpdateUserAuthorizationRequest extends FormRequest
{
    use ValidatesPermissionDependencies;

    /**
     * Get the validation rules that apply to the request.
     *
     * Both `roles` and `permissions` must be present but may be empty
     * arrays — a user may validly hold zero roles and zero direct
     * permissions. Roles are validated against the DB-driven `roles`
     * table (open catalog); permissions are validated against the closed
     * `Permission` enum catalog.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'roles' => ['present', 'array'],
            'roles.*' => ['required', 'string', Rule::exists('roles', 'name')->where('guard_name', 'web')],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['required', Rule::enum(Permission::class)],
        ];
    }

    /**
     * Get custom error messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'roles.present' => __('modules/users/management.validation.roles.present'),
            'roles.array' => __('modules/users/management.validation.roles.array'),
            'roles.*.exists' => __('modules/users/management.validation.roles.exists'),
            'permissions.present' => __('modules/users/management.validation.permissions.present'),
            'permissions.array' => __('modules/users/management.validation.permissions.array'),
            'permissions.*.enum' => __('modules/users/management.validation.permissions.enum'),
        ];
    }

    /**
     * Get the "after" validation callables for the request.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            $this->validatePermissionDependencies(...),
        ];
    }

    /**
     * Reject an assignment whose effective permissions grant a permission
     * without the permissions it depends on. A dependency is satisfied by
     * either a submitted direct permission or a permission of a submitted
     * role. Skipped when either set itself is invalid.
     */
    private function validatePermissionDependencies(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['roles', 'roles.*', 'permissions', 'permissions.*'])) {
            return;
        }

        $rolePermissions = PermissionModel::query()
            ->whereHas('roles', fn (Builder $roles): Builder => $roles
                ->where('roles.guard_name', 'web')
                ->whereIn('roles.name', $this->input('roles', [])))
            ->pluck('name')
            ->all();

        $this->addMissingPermissionDependencyErrors(
            $validator,
            [...$this->input('permissions', []), ...$rolePermissions],
            'modules/users/management.validation.permissions.requires',
        );
    }
}
