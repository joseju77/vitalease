<?php

namespace App\Http\Requests\Roles;

use App\Enums\Permission;
use App\Http\Requests\Concerns\ValidatesPermissionDependencies;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreRoleRequest extends FormRequest
{
    use ValidatesPermissionDependencies;

    /**
     * Get the validation rules that apply to the request.
     *
     * `permissions` must be present but may be an empty array — a role may
     * validly hold zero permissions at creation time.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('roles', 'name')->where('guard_name', 'web')],
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
     * Reject a permission set that grants a permission without the
     * permissions it depends on. Skipped when the set itself is invalid.
     */
    private function validatePermissionDependencies(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['permissions', 'permissions.*'])) {
            return;
        }

        $this->addMissingPermissionDependencyErrors(
            $validator,
            $this->input('permissions', []),
            'modules/roles/management.validation.permissions.requires',
        );
    }
}
