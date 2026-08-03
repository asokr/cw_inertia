<?php

namespace App\Http\Requests\Web\Subscriber;

use Illuminate\Foundation\Http\FormRequest;

class StoreAbExperimentPhotosRequest extends FormRequest
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
            'photos' => ['required', 'array', 'min:1', 'max:6'],
            'photos.*' => ['required', 'file', 'max:10240', 'mimes:jpeg,jpg,png,webp'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'photos.required' => 'Выберите хотя бы одну фотографию.',
            'photos.max' => 'За один раз можно загрузить не более 6 файлов.',
            'photos.*.mimes' => 'Допустимы только JPEG, PNG и WEBP.',
            'photos.*.max' => 'Размер файла не должен превышать 10 МБ.',
        ];
    }
}
