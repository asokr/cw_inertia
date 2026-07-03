<?php

namespace App\Http\Requests\Web\Subscriber;

use Illuminate\Foundation\Http\FormRequest;

class RepricerLogsRequest extends FormRequest
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
            'nmID' => ['required', 'integer'],
            'strategy' => ['nullable', 'string', 'in:TIME,STOCKS'],
        ];
    }
}