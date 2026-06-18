<?php

namespace App\Http\Requests\Roles;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoleRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * `permissions` must be present but may be an empty array — a role may
     * validly hold zero permissions.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('roles', 'name')->where('guard_name', 'web')->ignore($this->route('role')),
            ],
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
            'name.required' => __('modules/roles/management.validation.name.required'),
            'name.max' => __('modules/roles/management.validation.name.max'),
            'name.unique' => __('modules/roles/management.validation.name.unique'),
            'permissions.present' => __('modules/roles/management.validation.permissions.present'),
            'permissions.array' => __('modules/roles/management.validation.permissions.array'),
            'permissions.*.enum' => __('modules/roles/management.validation.permissions.enum'),
        ];
    }
}
