<?php

namespace App\Http\Requests\Web\Subscriber;

use App\Services\Subscriber\Wb\WbAbTestingService;
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
            'sum' => ['required', 'integer', 'min:'.WbAbTestingService::MIN_BUDGET_DEPOSIT],
            'experiment_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $min = WbAbTestingService::MIN_BUDGET_DEPOSIT;

        return [
            'sum.required' => 'Укажите сумму пополнения.',
            'sum.min' => 'Минимальная сумма пополнения — '.$min.' ₽.',
        ];
    }
}
