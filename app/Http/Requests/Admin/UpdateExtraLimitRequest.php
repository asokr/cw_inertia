<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateExtraLimitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'slug' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-z0-9_]+$/',
                Rule::unique('extra_limits', 'slug')->ignore($this->routeExtraLimitId()),
            ],
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    private function routeExtraLimitId(): ?int
    {
        $param = $this->route('extraLimit');

        if ($param instanceof \App\Models\ExtraLimits) {
            return (int) $param->id;
        }

        return is_numeric($param) ? (int) $param : null;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.regex' => 'Slug: только латиница в нижнем регистре, цифры и подчёркивание',
            'slug.unique' => 'Такой slug уже существует',
        ];
    }
}
