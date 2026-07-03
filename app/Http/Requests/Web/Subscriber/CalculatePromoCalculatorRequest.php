<?php

namespace App\Http\Requests\Web\Subscriber;

use Illuminate\Foundation\Http\FormRequest;

class CalculatePromoCalculatorRequest extends FormRequest
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
            'file' => ['required', 'string', 'max:255'],
            'cabinet_id' => ['required', 'integer', 'exists:wb_price_cabinets,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Нет отчёта по акциям. Загрузите его.',
            'cabinet_id.required' => 'Вы не выбрали кабинет из инструмента Ценообразования',
        ];
    }
}