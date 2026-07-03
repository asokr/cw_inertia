<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreExtraLimitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'price' => 'required|numeric|min:0',
            'limit_name' => 'required|string|max:255',
            'quantity' => 'required|numeric|min:0',
            'order' => 'nullable|numeric|min:0',
        ];
    }
}