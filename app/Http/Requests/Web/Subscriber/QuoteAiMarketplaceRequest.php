<?php

namespace App\Http\Requests\Web\Subscriber;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class QuoteAiMarketplaceRequest extends FormRequest
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
            'kind' => ['required', 'string', Rule::in(['text', 'image', 'video'])],
            'task_type' => ['nullable', 'string'],
            'resolution' => ['nullable', 'string'],
            'duration' => ['nullable', 'integer', 'min:1', 'max:15'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'kind.required' => 'Укажите тип операции',
            'kind.in' => 'Допустимые типы: текст, изображение, видео',
        ];
    }
}
