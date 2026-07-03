<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class IndexWbApiUsageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date' => 'nullable|date_format:Y-m-d',
            'per_page' => 'nullable|integer|min:5|max:100',
            'legal_entity' => 'nullable|string|max:255',
            'seller_id' => 'nullable|string|max:255',
        ];
    }
}