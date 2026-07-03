<?php

namespace App\Http\Requests\Web\Subscriber;

use Illuminate\Foundation\Http\FormRequest;

class StoreTemplateRequest extends FormRequest
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
            'text' => ['required', 'string', 'min:10', 'max:1200'],
            'minRating' => ['required', 'integer', 'min:1', 'max:5'],
            'maxRating' => ['required', 'integer', 'min:1', 'max:5', 'gte:minRating'],
        ];
    }
}