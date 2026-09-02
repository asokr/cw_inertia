<?php

namespace App\Http\Requests\Admin;

use App\Models\Credits\AiCabinetAnalyzerCreditTariff;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAiCabinetAnalyzerCreditTariffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'provider' => ['required', 'string', Rule::in(AiCabinetAnalyzerCreditTariff::PROVIDERS)],
            'model' => ['required', 'string', 'max:120'],
            'input_credits_per_1k' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'output_credits_per_1k' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'coefficient' => ['nullable', 'numeric', 'min:0.0001', 'max:100'],
            'is_default' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'provider.required' => 'Укажите провайдера',
            'provider.in' => 'Неизвестный провайдер',
            'model.required' => 'Укажите модель',
            'input_credits_per_1k.required' => 'Укажите стоимость входящих данных',
            'output_credits_per_1k.required' => 'Укажите стоимость ответа',
        ];
    }
}
