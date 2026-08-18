<?php

namespace App\Http\Requests\Web\Subscriber;

use Illuminate\Foundation\Http\FormRequest;

class BuyCreditsRequest extends FormRequest
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
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'quantity.required' => 'Укажите количество',
            'quantity.min' => 'Количество должно быть не меньше 1',
            'quantity.max' => 'Слишком большое количество',
        ];
    }
}
