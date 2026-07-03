<?php

namespace App\Http\Requests\Web\Subscriber;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOzAiDataRequest extends FormRequest
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
            'status' => ['required'],
            'ratings' => ['present', 'array'],
            'ratings.*' => ['integer', 'min:1', 'max:5'],
            'empty_answer' => ['nullable', 'boolean'],
            'signature' => ['nullable', 'string', 'max:500'],
        ];
    }
}