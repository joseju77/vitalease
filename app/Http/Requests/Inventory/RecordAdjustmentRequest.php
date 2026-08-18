<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\Attributes\FailOnUnknownFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

#[FailOnUnknownFields]
class RecordAdjustmentRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * `quantity` may be positive or negative but never zero, and `notes` is
     * always required: an Adjustment is a manual correction and must explain
     * its reason. Zero is rejected in {@see self::after()} rather than with
     * `not_in`, to avoid the loose string comparison `not_in` performs.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'quantity' => ['required', 'integer'],
            'notes' => ['required', 'string', 'max:1024'],
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
            $this->validateQuantityNotZero(...),
            $this->validateNotesNotBlank(...),
        ];
    }

    /**
     * Reject a zero quantity: `Adjustment` must always move stock.
     */
    private function validateQuantityNotZero(Validator $validator): void
    {
        if ($validator->errors()->has('quantity')) {
            return;
        }

        if ((int) $this->input('quantity') === 0) {
            $validator->errors()->add('quantity', __('modules/inventory/management.custom.adjustment_quantity_nonzero'));
        }
    }

    /**
     * Reject notes that are present but only whitespace, matching the
     * `chk_adjustment_notes` database constraint.
     */
    private function validateNotesNotBlank(Validator $validator): void
    {
        if ($validator->errors()->has('notes')) {
            return;
        }

        if (trim((string) $this->input('notes')) === '') {
            $validator->errors()->add('notes', __('modules/inventory/management.custom.adjustment_notes_required'));
        }
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
