<?php

namespace App\Http\Requests\Users;

use Illuminate\Foundation\Http\FormRequest;

class StoreUserRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
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
            'name.required' => __('modules/users/management.validation.name.required'),
            'name.max' => __('modules/users/management.validation.name.max'),
            'email.required' => __('modules/users/management.validation.email.required'),
            'email.email' => __('modules/users/management.validation.email.email'),
            'email.max' => __('modules/users/management.validation.email.email'),
            'email.unique' => __('modules/users/management.validation.email.unique'),
            'password.required' => __('modules/users/management.validation.password.required'),
            'password.min' => __('modules/users/management.validation.password.min'),
        ];
    }
}
