<?php

namespace App\Http\Requests\Web;

use Illuminate\Foundation\Http\FormRequest;

class SupportMessageRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:190'],
            'phone' => ['required', 'string', 'regex:/^\+?[0-9]{10,15}$/'],
            'message' => ['required', 'string', 'max:2000'],
            'source' => ['nullable', 'string', 'max:100'],
            'context_email' => ['nullable', 'string', 'email', 'max:190'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Укажите имя',
            'phone.required' => 'Укажите телефон',
            'phone.regex' => 'Некорректный формат телефона',
            'message.required' => 'Напишите сообщение',
            'message.max' => 'Сообщение слишком длинное',
        ];
    }
}