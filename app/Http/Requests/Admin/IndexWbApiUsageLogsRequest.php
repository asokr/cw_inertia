<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class IndexWbApiUsageLogsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date' => 'nullable|date_format:Y-m-d',
            'per_page' => 'nullable|integer|min:10|max:100',
            'page' => 'nullable|integer|min:1',
            'endpoint' => 'nullable|string|max:500',
            'method' => 'nullable|string|in:GET,POST,PUT,PATCH,DELETE',
            'response_code' => 'nullable',
        ];
    }
}