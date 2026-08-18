<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AdjustSubscriberCreditsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'subscription_delta' => 'required|integer',
            'purchased_delta' => 'required|integer',
            'reason' => 'required|string|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Укажите причину корректировки',
        ];
    }
}
