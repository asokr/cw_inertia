<?php

namespace App\Http\Requests\Web\Subscriber;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOzCabinetRequest extends FormRequest
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
            'name' => ['required', 'string', 'min:1', 'max:190'],
            'apikey' => ['required', 'string', 'max:5000'],
            'empty_answer' => ['nullable'],
            'signature' => ['nullable', 'string', 'max:500'],
        ];
    }
}