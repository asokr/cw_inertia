<?php

namespace App\Http\Requests\Web\Subscriber;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RefreshAiLimitsRequest extends FormRequest
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
            'limit' => ['required', 'string', Rule::in(['ai_text_query', 'ai_image_query', 'ai_video_query'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'limit.required' => 'Нужно передать лимит',
            'limit.in' => 'Указан недопустимый лимит',
        ];
    }
}