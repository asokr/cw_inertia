<?php

namespace App\Http\Requests\Web\Subscriber;

use Illuminate\Foundation\Http\FormRequest;

class StartAiReferenceVideoRequest extends FormRequest
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
            'prompt' => ['required', 'string'],
            'images' => ['required', 'array', 'min:1', 'max:7'],
            'images.*' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'prompt.required' => 'Не передан prompt',
            'images.required' => 'Передайте хотя бы одно изображение',
            'images.min' => 'Передайте хотя бы одно изображение',
            'images.max' => 'Максимум 7 изображений',
        ];
    }
}