<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCreditServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'integer', 'min:1', 'max:100000'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.required' => 'Укажите стоимость в кредитах',
            'amount.min' => 'Стоимость должна быть не меньше 1 кредита',
        ];
    }
}
