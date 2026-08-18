<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCreditRublesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rubles_per_credit' => ['required', 'numeric', 'min:0', 'max:100000'],
        ];
    }

    public function messages(): array
    {
        return [
            'rubles_per_credit.required' => 'Укажите стоимость одного кредита',
            'rubles_per_credit.numeric' => 'Стоимость одного кредита должна быть числом',
            'rubles_per_credit.min' => 'Стоимость одного кредита не может быть отрицательной',
        ];
    }
}
