<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class IndexPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'per_page' => 'nullable|integer|min:5|max:100',
            'sort_field' => 'nullable|string|in:id,amount,status,created_at',
            'sort_order' => 'nullable|string|in:asc,desc',
        ];
    }
}