<?php

namespace App\Http\Requests\Web\Subscriber;

use Illuminate\Foundation\Http\FormRequest;

class StoreRepricerStockRequest extends FormRequest
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
            'name' => ['nullable', 'string', 'max:190'],
            'nmID' => ['required', 'integer'],
            'strategy' => ['required', 'integer', 'in:1,2'],
            'terms' => ['required', 'array'],
            'status' => ['required', 'boolean'],
            'base_value' => ['nullable', 'numeric'],
            'base_discount' => ['nullable', 'numeric'],
        ];
    }
}