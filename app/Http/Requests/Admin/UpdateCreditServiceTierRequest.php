<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCreditServiceTierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'param_value' => ['nullable', 'string', 'max:32'],
            'amount' => ['required', 'integer', 'min:1', 'max:100000'],
        ];
    }
}
