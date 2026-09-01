<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\Attributes\FailOnUnknownFields;
use Illuminate\Foundation\Http\FormRequest;

#[FailOnUnknownFields]
class RecordEntryRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * An Entry always increases stock, so `quantity` must be strictly
     * positive; the `chk_quantity_sign_by_type` constraint backs this up at
     * the database level.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'quantity' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:1024'],
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
}
