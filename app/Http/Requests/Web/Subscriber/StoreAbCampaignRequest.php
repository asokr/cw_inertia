<?php

namespace App\Http\Requests\Web\Subscriber;

use App\Services\Subscriber\Wb\WbAbTestingService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreAbCampaignRequest extends FormRequest
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
            'experiment_id' => ['required', 'integer', 'min:1'],
            'name' => ['nullable', 'string', 'max:255'],
            'bid_type' => ['nullable', 'string', Rule::in(['manual', 'unified'])],
            'payment_type' => ['nullable', 'string', Rule::in(['cpm', 'cpc'])],
            'placement_types' => ['nullable', 'array'],
            'placement_types.*' => ['string', Rule::in(['search', 'recommendations'])],
            'budget_deposit' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'experiment_id.required' => 'Не выбран эксперимент.',
            'bid_type.in' => 'Некорректный тип ставки.',
            'payment_type.in' => 'Некорректный тип оплаты.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $sum = (int) $this->input('budget_deposit', 0);
            if ($sum <= 0) {
                return;
            }

            $min = WbAbTestingService::MIN_BUDGET_DEPOSIT;
            if ($sum < $min) {
                $validator->errors()->add(
                    'budget_deposit',
                    'Минимальная сумма пополнения бюджета — '.$min.' ₽',
                );
            }
            if ($sum % 50 !== 0) {
                $validator->errors()->add(
                    'budget_deposit',
                    'Сумма пополнения бюджета должна быть кратна 50 ₽',
                );
            }
        });
    }
}
