<?php

namespace App\Http\Requests\Users;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserAuthorizationRequest extends FormRequest
{
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
}
