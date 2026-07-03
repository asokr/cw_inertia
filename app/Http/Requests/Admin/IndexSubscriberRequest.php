<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class IndexSubscriberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => 'nullable|string|min:2',
            'plan_id' => 'nullable|integer|exists:subscribers_plans,id',
            'per_page' => 'nullable|integer|min:5|max:100',
            'sort_field' => 'nullable|string|in:id,user_id,status,created_at',
            'sort_order' => 'nullable|string|in:asc,desc',
        ];
    }
}