<?php

namespace App\Http\Requests\Web\Subscriber;

use Illuminate\Foundation\Http\FormRequest;

class LoadRepricerStockSizesRequest extends FormRequest
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
            'sizes' => ['nullable', 'boolean'],
        ];
    }
}