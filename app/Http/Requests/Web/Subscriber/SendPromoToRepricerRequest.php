<?php

namespace App\Http\Requests\Web\Subscriber;

use Illuminate\Foundation\Http\FormRequest;

class SendPromoToRepricerRequest extends FormRequest
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
            'cabinet_id' => ['required', 'integer', 'exists:wb_repricer_cabinets,id'],
            'data' => ['required', 'array', 'min:1'],
            'data.*.nm_id' => ['required', 'integer', 'min:1'],
            'data.*.plan_price' => ['required', 'numeric', 'min:0'],
            'dates' => ['required', 'array'],
            'dates.start' => ['required', 'date'],
            'dates.end' => ['required', 'date', 'after:dates.start'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'required' => 'Не хватает данных для передачи в репрайсер',
        ];
    }
}