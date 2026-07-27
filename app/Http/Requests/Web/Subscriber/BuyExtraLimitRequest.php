<?php

namespace App\Http\Requests\Web\Subscriber;

use Illuminate\Foundation\Http\FormRequest;

class BuyExtraLimitRequest extends FormRequest
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
            'id' => ['required', 'integer', 'exists:extra_limits,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'id.required' => 'Недостаточно данных',
            'id.exists' => 'Ошибка в данных',
            'quantity.required' => 'Укажите количество',
            'quantity.min' => 'Количество должно быть не меньше 1',
            'quantity.max' => 'Слишком большое количество',
        ];
    }
}
