<?php

namespace App\Http\Requests\Web\Auth;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:190', 'unique:users'],
            'password' => ['required', 'string', 'between:6,190', 'confirmed'],
            'phone' => ['nullable', 'string', 'regex:/^\+?[0-9]{10,15}$/'],
            'coupon_code' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'Этот E-mail уже занят',
            'email.email' => 'Похоже, что E-mail ненастоящий',
            'password.confirmed' => 'Пароли не совпадают',
            'password.between' => 'Пароль должен состоять минимум из 6 символов',
            'phone.regex' => 'Некорректный формат телефона',
        ];
    }
}