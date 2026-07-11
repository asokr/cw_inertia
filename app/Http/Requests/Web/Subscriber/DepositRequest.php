<?php

namespace App\Http\Requests\Web\Subscriber;

use Illuminate\Foundation\Http\FormRequest;

class DepositRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:1'],
            'plan_id' => ['nullable', 'integer', 'exists:subscribers_plans,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.numeric' => 'Сумма пополнения должна быть числом',
            'amount.required' => 'Укажите сумму пополнения',
            'amount.min' => 'Минимальная сумма — 1 ₽',
        ];
    }
}