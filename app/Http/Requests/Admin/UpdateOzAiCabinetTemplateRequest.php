<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOzAiCabinetTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'system_prompt' => 'required|string',
            'sort_order' => 'nullable|integer|min:0|max:10000',
            'is_active' => 'nullable|boolean',
            'response_format' => 'nullable|in:json,markdown',
            'data_sources' => ['required', 'array', 'min:1'],
            'data_sources.*' => ['string', 'in:products,analytics,search,stocks,advertising'],
        ];
    }

    public function messages(): array
    {
        return [
            'data_sources.required' => 'Выберите хотя бы один источник данных для анализа.',
            'data_sources.min' => 'Выберите хотя бы один источник данных для анализа.',
            'data_sources.*.in' => 'Недопустимый источник данных.',
        ];
    }
}
