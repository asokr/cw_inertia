<?php

namespace App\Http\Requests\Web\Subscriber;

use App\Support\Wb\RepricerTimePeriod;
use Illuminate\Foundation\Http\FormRequest;

class StoreRepricerTimeSettingRequest extends FormRequest
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
            'name' => ['nullable', 'string', 'max:190'],
            'nmID' => ['required', 'integer'],
            'price_type' => ['required', 'string', 'in:PRICE,DISCOUNT'],
            'strategy' => ['required', 'string', 'in:TIME,STOCK'],
            'pricing_modifier_type' => ['required', 'string', 'in:PROCENT,FIXED'],
            'terms' => ['required', 'array', 'min:1'],
            'terms.*.start' => ['required', 'string', RepricerTimePeriod::validationRule()],
            'terms.*.end' => ['required', 'string', RepricerTimePeriod::validationRule()],
            'terms.*.value' => ['required', 'numeric'],
            'status' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'terms.*.start.required' => 'Укажите начало периода',
            'terms.*.end.required' => 'Укажите конец периода',
        ];
    }
}
