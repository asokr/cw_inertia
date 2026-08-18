<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAiCabinetTemplateRequest extends FormRequest
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
            'data_sources.*' => ['string', 'in:ads,reviews,funnel'],
            'credits_cost' => ['required', 'integer', 'min:1', 'max:1000000'],
        ];
    }

    public function messages(): array
    {
        return [
            'data_sources.required' => 'Выберите хотя бы один источник данных для анализа.',
            'data_sources.min' => 'Выберите хотя бы один источник данных для анализа.',
            'data_sources.*.in' => 'Недопустимый источник данных.',
            'credits_cost.required' => 'Укажите стоимость отчёта в кредитах.',
            'credits_cost.integer' => 'Стоимость отчёта должна быть целым числом.',
            'credits_cost.min' => 'Стоимость отчёта должна быть не меньше 1 кредита.',
        ];
    }
}