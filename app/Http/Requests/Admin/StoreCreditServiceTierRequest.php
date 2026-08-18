<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreCreditServiceTierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'param_value' => ['required', 'string', 'max:32'],
            'amount' => ['required', 'integer', 'min:1', 'max:100000'],
        ];
    }

    public function messages(): array
    {
        return [
            'param_value.required' => 'Укажите разрешение',
            'amount.required' => 'Укажите стоимость в кредитах',
            'amount.min' => 'Стоимость должна быть не меньше 1 кредита',
        ];
    }
}
