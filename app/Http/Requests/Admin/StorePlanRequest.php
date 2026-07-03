<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'duration' => 'required|integer|min:1',
            'description' => 'nullable|string',
            'limits_plan' => 'nullable',
            'limits_month' => 'nullable',
            'permissions' => 'required|array|min:1',
            'permissions.*' => [
                'required',
                'string',
                Rule::exists('permissions', 'name')->where(function ($query) {
                    $query->where('name', 'subscriber')
                        ->orWhere('name', 'like', 'subscriber %');
                }),
            ],
            'status' => 'required|boolean',
            'hidden' => 'required|boolean',
        ];
    }
}