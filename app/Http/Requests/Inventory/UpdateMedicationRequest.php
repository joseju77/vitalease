<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\Attributes\FailOnUnknownFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

#[FailOnUnknownFields]
class UpdateMedicationRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * Same shape as {@see StoreMedicationRequest}: `current_stock` is never
     * an accepted key, and `is_active` toggling is also available through
     * the dedicated `activate`/`deactivate` endpoints.
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
                Rule::unique('medications')
                    ->where('presentation', $this->input('presentation'))
                    ->where('concentration', $this->input('concentration'))
                    ->ignore($this->route('medication')),
            ],
            'presentation' => ['required', 'string', 'max:64'],
            'concentration' => ['required', 'string', 'max:64'],
            'dispensing_unit' => ['required', 'string', 'max:32'],
            'minimum_stock' => ['required', 'integer', 'min:0'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return __('modules/inventory/management.attributes');
    }

    /**
     * Get custom error messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => __('modules/inventory/management.custom.duplicate_medication'),
        ];
    }
}
