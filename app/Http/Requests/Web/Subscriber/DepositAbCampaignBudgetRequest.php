<?php

namespace App\Http\Requests\Web\Subscriber;

use Illuminate\Foundation\Http\FormRequest;

class DepositAbCampaignBudgetRequest extends FormRequest
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
            'sum' => ['required', 'integer', 'min:1000'],
            'experiment_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sum.required' => 'Укажите сумму пополнения.',
            'sum.min' => 'Минимальная сумма пополнения — 1000 ₽.',
        ];
    }
}
