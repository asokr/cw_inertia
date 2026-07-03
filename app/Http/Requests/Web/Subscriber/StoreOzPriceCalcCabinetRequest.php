<?php

namespace App\Http\Requests\Web\Subscriber;

use Illuminate\Foundation\Http\FormRequest;

class StoreOzPriceCalcCabinetRequest extends FormRequest
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
            'name' => ['required', 'string', 'min:1', 'max:255'],
            'client_id' => ['required', 'string', 'max:255'],
            'apikey' => ['required', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Укажите название кабинета',
            'client_id.required' => 'Укажите Client ID',
            'apikey.required' => 'Укажите API-ключ',
        ];
    }
}