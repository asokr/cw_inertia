<?php

namespace App\Http\Requests\Web\Subscriber;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAiDataRequest extends FormRequest
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
            'review_type' => ['nullable'],
        ];
    }
}