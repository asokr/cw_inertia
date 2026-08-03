<?php

namespace App\Http\Requests\Web\Subscriber;

use Illuminate\Foundation\Http\FormRequest;

class ReplaceAbExperimentPhotoRequest extends FormRequest
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
            'photo' => ['required', 'file', 'max:10240', 'mimes:jpeg,jpg,png,webp'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'photo.required' => 'Выберите файл изображения.',
            'photo.mimes' => 'Допустимы только JPEG, PNG и WEBP.',
            'photo.max' => 'Размер файла не должен превышать 10 МБ.',
        ];
    }
}
