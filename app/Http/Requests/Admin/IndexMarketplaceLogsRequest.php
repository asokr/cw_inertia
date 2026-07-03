<?php

namespace App\Http\Requests\Admin;

use App\Enums\AiTaskType;
use Illuminate\Foundation\Http\FormRequest;

class IndexMarketplaceLogsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:10|max:100',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'task_type' => 'nullable|string|in:' . implode(',', AiTaskType::values()),
            'status_code' => 'nullable|integer|min:100|max:599',
            'search' => 'nullable|string|max:100',
        ];
    }
}